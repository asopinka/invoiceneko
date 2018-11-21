<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\CompanyUserRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Log;
use App\Models\User;
use App\Models\Company;
use Illuminate\Http\Request;
use Google2FA;
use PragmaRX\Recovery\Recovery;
use PragmaRX\Countries\Package\Countries;

class UserController extends Controller
{
    public function __construct(){
        $this->countries = new Countries();
    }

    /**
     * Display a listing of the resource.
     *
     * @return void
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @param Request $request
     * @param null $token
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        $token = null;
        if ($request->query->has('token'))
        {
            $token = $request->query->get('token');
            $companyUserRequest = CompanyUserRequest::where('token', $token)->first();
            session(['_old_input.full_name' => $companyUserRequest->full_name]);
            session(['_old_input.email' => $companyUserRequest->email]);
            session(['_old_input.phone' => $companyUserRequest->phone]);
        }

        $countries = $this->countries->all();
        $timezones = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL);

        return view('pages.user.create', compact('token', 'countries', 'timezones'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param CreateUserRequest $request
     * @return \Illuminate\Http\Response
     */
    public function store(CreateUserRequest $request)
    {
        $user = new User;
        $user->fill($request->all());
        $user->password = $request->input('password');
        if($request->has('country_code') && !is_null($request->input('country_code')))
        {
            if($request->has('timezone') && is_null($request->input('timezone')))
            {
                $timezone = $this->countries
                    ->where('iso_3166_1_alpha2', $request->input('country_code'))
                    ->first()
                    ->hydrate('timezones')
                    ->timezones
                    ->first()
                    ->zone_name;
                $user->timezone = $timezone;
            }
        }
        $user->save();

        if ($request->query->has('token'))
        {
            $token = $request->query->get('token');
            $companyUserRequest = CompanyUserRequest::where('token', $token)->first();
            $user->save();

            $company = Company::findOrFail($companyUserRequest->company_id);
            $company->users()->attach($user->id);

            $companyUserRequest->delete();

            session()->forget('_old_input.full_name');
            session()->forget('_old_input.email');
            session()->forget('_old_input.phone');

            flash('You can now sign in', 'success');

            return redirect()->route('auth.show');
        }

        $request->session()->put('user_id', $user->id);

        return redirect()->route('company.create');
    }

    /**
     * Display the specified resource.
     *
     * @return void
     */
    public function show()
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function edit()
    {
        $user = auth()->user();
        $countries = $this->countries->all();
        $timezones = \DateTimeZone::listIdentifiers(\DateTimeZone::ALL);
        return view('pages.user.edit', compact('user', 'countries', 'timezones'));
    }

    /**
     * Retrieve the user and return as object
     *
     * @param  \App\Models\User $user
     * @return ItemTemplate
     */
    public function retrieve(User $user)
    {
        $auth_user = auth()->user();
        $usercompany = $user->company;

        //TODO: Probably need to rewrite/refactor this logic to somewhere else
        if ($usercompany)
        {
            if ($usercompany->isOwner($auth_user))
            {
                return response()->json($user);
            }
            else
            {
                return abort(401);
            }
        }
        else
        {
            return abort(401);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateUserRequest $request
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateUserRequest $request)
    {
        $user = auth()->user();

        if (Hash::check($request->input('password'), $user->password)) {
            $user->fill($request->all());

            if($request->has('country_code') && !is_null($request->input('country_code')))
            {
                if($request->has('timezone') && is_null($request->input('timezone')))
                {
                    $timezone = $this->countries
                        ->where('iso_3166_1_alpha2', $request->input('country_code'))
                        ->first()
                        ->hydrate('timezones')
                        ->timezones
                        ->first()
                        ->zone_name;
                    $user->timezone = $timezone;
                }
            }

            if ($request->has('newpassword') && $request->input('newpassword') != null) {
                $newpass = $request->input('newpassword');
                $user->password = $newpass;
            }

            if (!$user->save()) {
                flash('Failed to Update Profile', 'error');
                return redirect()->back();
            } else {
                flash('Successfully Updated Profile', 'success');
                return redirect()->back();
            }
        }

        return redirect()->back();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return void
     */
    public function destroy()
    {
        //
    }

    public function security()
    {
        $user = auth()->user();

        return view('pages.user.security', compact('user'));
    }

    public function multifactor_start(Company $company)
    {
        return redirect()->route('user.multifactor.create', [ 'company' => $company->domain_name ]);
    }

    public function multifactor_create(Company $company)
    {
        $user = auth()->user();
        if(is_null($user->twofa_secret))
        {
            $twofa_secret = Google2FA::generateSecretKey(32);
            session()->put('twofa_secret', $twofa_secret);
        }
        else
        {
            flash('Multifactor Auth is already enabled', 'warning');
            return redirect()->route('user.security', [ 'company' => $company->domain_name ]);
        }

        $twoFactorUrl = Google2FA::getQRCodeUrl(
            config('app.name'),
            $user->email,
            $twofa_secret
        );

        return view('pages.user.multifactor.create', compact('twoFactorUrl', 'twofa_secret'));
    }

    public function multifactor_store(Request $request, Company $company)
    {
        $multifactor_code = $request->input('multifactor_code');
        $twofa_secret = session()->pull('twofa_secret');
        $twofa_timestamp = Google2FA::getTimestamp();

        $valid = Google2FA::verifyKey($twofa_secret, $multifactor_code);

        if ($valid !== false) {
            $recovery = new Recovery();
            $codesJSON = $recovery->toJson();
            $codes = $recovery->toCollection();

            $user = auth()->user();
            $user->twofa_secret = $twofa_secret;
            $user->twofa_timestamp = $twofa_timestamp;
            $user->twofa_backup_codes = $codesJSON;
            $user->save();

            flash("Multifactor Auth has been enabled for your account", 'success');
            return redirect()->route('user.security', [ 'company' => $company->domain_name ])->with(compact( 'codes'));

        } else {
            flash("Something went wrong, please try again", 'error');
            return redirect()->back();
        }

    }

    public function multifactor_destroy(Company $company)
    {
        $user = auth()->user();
        $user->twofa_secret = null;
        $user->twofa_timestamp = null;
        $user->twofa_backup_codes = null;
        $user->save();

        flash("Multifactor Auth has been disabled for your account", 'warning');
        return redirect()->back();
    }

    public function multifactor_regenerate_codes(Request $request, Company $company)
    {
        $recovery = new Recovery();
        $codesJSON = $recovery->toJson();
        $codes = collect($recovery->toCollection());

        $user = auth()->user();
        $user->twofa_backup_codes = $codesJSON;
        $user->save();

        flash("Your backup codes have been regenerated", 'success');
        return redirect()->route('user.security', [ 'company' => $company->domain_name ])->with(compact('codes'));
    }

    public function multifactor_backup()
    {
        return view('pages.multifactor-backup');
    }

    public function multifactor_backup_validate(Request $request, Company $company)
    {
        $code = $request->input('multifactor-backup-code');
        $user = auth()->user();

        $backup_codes = json_decode($user->twofa_backup_codes);

        foreach($backup_codes as $key => $backup_code)
        {
            if($backup_code === $code)
            {
                unset($backup_codes[$key]); // remove item at index 0
                $backup_codes = array_values($backup_codes);
                $user->twofa_timestamp = Google2FA::getTimestamp();
                $user->twofa_backup_codes = json_encode($backup_codes);
                $user->save();

                session()->put('multifactor_status',[
                    "otp_timestamp" => true,
                    "auth_passed" => true,
                    "auth_time" => Carbon::now()
                ]);

                return redirect()->route('dashboard', [ 'company' => $company->domain_name ]);
            }
            else
            {
                continue;
            }
        }

        flash("That is an invalid backup code", 'error');
        return redirect()->back();
    }
}
