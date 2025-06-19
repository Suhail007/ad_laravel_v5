<?php

// app/Http/Controllers/Auth/LoginController.php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;

use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Http\Controllers\Controller;
use App\Models\RegisterRequest;
use App\Models\User;
use App\Models\UserMeta;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use MikeMcLin\WpPassword\Facades\WpPassword;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    public function login(Request $request)
    {
        $email = $request->input('user_email');
        $hashedPassword = $request->input('password');

        $user = User::where('user_email', $email)->orWhere('user_login', $email)->first();
        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid credentials',
            ]);
        }

        $check = WpPassword::check($hashedPassword, $user->user_pass);
        if ($check == true) {
            // // lines rollback in live mode
            if ($user->approved == "0") {
                return response()->json([
                    'status' => false,
                    'message' => 'Your Register Request Not Approved',
                ]);
            }

            if($user->approved == "2") {
                return response()->json([
                    'status' => false,
                    'message' => 'Your Register Request Rejected',
                ]);
            }

            $currentApiServer = Cache::get('current_api_server', 1);

            $newApiServer = $currentApiServer % 3 + 1;

            Cache::put('current_api_server', $newApiServer);

            $data = [
                'ID' => $user->ID,
                'name' => $user->user_login,
                'email' => $user->user_email,
                'capabilities' => $user->capabilities,
                'account_no' => $user->account,
                'api_server' => $newApiServer,
            ];

            if ($token = JWTAuth::fromUser($user)) {
                return response()->json([
                    'status' => 'success',
                    'token' => $token,
                    'data' => $data,
                ]);
            }
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid credentials',
            ],);
        }
    }


    // public function login(Request $request)
    // {
    //     $email = $request->input('user_email');
    //     $hashedPassword = $request->input('password');
    //     $user = User::where('user_email', $email)->orWhere('user_login', $email)->first();
    //     if(!$user){
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Invalid credentials',
    //         ]);
    //     }
    //     $check = WpPassword::check($hashedPassword, $user->user_pass);
    //     if ($check == true) {
    //         if ($user->approved == "0") {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Your Register Request Not Approved',
    //             ]);
    //         }
    //         $data = [
    //             'ID' => $user->ID,
    //             'name' => $user->user_login,
    //             'email' => $user->user_email,
    //             'capabilities' => $user->capabilities,
    //             'account_no' => $user->account
    //         ];
    //         if ($token = JWTAuth::fromUser($user)) {
    //             return response()->json([
    //                 'status' => 'success',
    //                 'token' => $token,
    //                 'data' => $data,
    //             ]);
    //         }
    //     } else {
    //         return response()->json([
    //             'status' => 'error',
    //             'message' => 'Invalid credentials',
    //         ], 401);
    //     }
    // }

    public function deleteMyAccount(Request $request)
{
    try {
        $user = JWTAuth::parseToken()->authenticate();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not authenticated']);
        }
        $data = $user->capabilities;
        foreach ($data as $key => $value) {
            if ($key == 'administrator') {
                return response()->json(['status' => false, 'message' => 'You are not allowed']);
            } else {
                $vuser = User::find($user->ID);
                $vuserMeta = UserMeta::where('user_id', $vuser->ID);
                if ($vuser) {
                    $vuserMeta->delete();
                    $vuser->delete();
                    return response()->json(['status' => true, 'message' => 'Account deleted successfully.']);
                }
            }
        }
        return response()->json(['status' => false, 'message' => 'You are not allowed']);
    } catch (\Throwable $th) {
        return response()->json(['status' => false, 'message' => 'Failed ' . $th->getMessage()]);
    }
}


    public function logout(Request $request)
    {
        try {
            $token = $request->header('Authorization');
            $token = str_replace('Bearer ', '', $token);
            JWTAuth::invalidate($token);
            // JWTAuth::invalidate($request->token);
            return response()->json(['status' => 'success', 'message' => 'User logged out successfully']);
        } catch (JWTException $exception) {
            return response()->json(['status' => 'error', 'message' => 'Could not log out the user'], 500);
        }
    }

    public function changePassword(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        if (!$user) {
            return response()->json(['message' => 'User not authenticated', 'status' => false], 401);
        }

        $validator = Validator::make($request->all(), [
            'password' => 'required|string|confirmed|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors(), 'status' => false], 422);
        }

        $user->update([
            'user_pass' => WpPassword::make($request->input('password')),
        ]);
        return response()->json(['message' => 'Password updated successfully', 'status' => true], 200);
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_email' => 'required|string|email|unique:wp_users,user_email',
            'password' => 'required|string|confirmed|min:6',
            'first_name' => 'required|string',
            'finlicenceurl' => 'required|string',
            'tobaccolicenceurl' => 'required|string',
            'taxidurl' => 'required|string',
            'govurl' => 'required|string',
            'last_name' => 'string',
            'billing_company' => 'nullable|string',
            'billing_address_1' => 'nullable|string',
            'billing_city' => 'nullable|string',
            'billing_state' => 'nullable|string',
            'billing_postcode' => 'nullable|string',
            'shipping_company' => 'nullable|string',
            'shipping_address_1' => 'nullable|string',
            'shipping_city' => 'nullable|string',
            'shipping_state' => 'nullable|string',
            'shipping_postcode' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()]);
        }
        $email = $request->input('user_email');
        $username = User::generateUniqueUsername($email);
        try {
            $useralready = User::where('user_email', $email)->first();
            if ($useralready) {
                return response()->json(['status' => false, 'message' => 'Already used for Email']);
            }
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => $th->getMessage()]);
        }
        $user = User::create([
            'user_login' => $username,
            'user_pass' => WpPassword::make($request->input('password')),
            'user_nicename' =>  $username,
            'user_email' => $request->input('user_email'),
            'user_registered' => Carbon::now(),
            'display_name' =>  $username,
        ]);

        $userMetaFields = [
            'nickname' =>  $username,
            'first_name' => $request->input('first_name'),
            'last_name' => $request->input('last_name'),
            'billing_company' => $request->input('billing_company'),
            'billing_address_1' => $request->input('billing_address_1'),
            'billing_city' => $request->input('billing_city'),
            'billing_state' => $request->input('billing_state'),
            'billing_postcode' => $request->input('billing_postcode'),
            'shipping_company' => $request->input('shipping_company'),
            'shipping_address_1' => $request->input('shipping_address_1'),
            'shipping_city' => $request->input('shipping_city'),
            'shipping_state' => $request->input('shipping_state'),
            'shipping_postcode' => $request->input('shipping_postcode'),
            'last_update' => $request->input('timestamp'), //time stamp
            'user_registration_number_box_1675806301' =>  $request->input('user_registration_number_box_1675806301'), //phone number
            'user_registration_file_1675806995815' =>  $request->input('user_registration_file_1675806995815') ?? 0, //file id Uplopad FEIN licence
            'user_registration_number_box_1678138943' =>  $request->input('user_registration_number_box_1678138943'), //fein number
            'user_registration_file_1675807041669' =>  $request->input('user_registration_file_1675807041669') ?? 0, //file id Upload Tobacco License
            'user_registration_file_1675806917' =>  $request->input('user_registration_file_1675806917') ?? 0, //file id Upload State Tax ID / Business License
            'user_registration_file_1675806973030' =>  $request->input('user_registration_file_1675806973030') ?? 0, //file id Government Issued ID (Driver’s license, State ID etc)
            'user_registration_select2_1676006057' => $request->input('user_registration_select2_1676006057'), //dropdown inspect name value (Distrubutor)
            'user_registration_select2_121' =>  $request->input('shipping_postcode'), //dropdown inspect name value (Wholesaler)
            'rich_editing' => 'TRUE',
            'syntax_highlighting' => 'TRUE',
            'comment_shortcuts' => 'FALSE',
            'admin_color' => 'fresh',
            'use_ssl' => '0',
            'show_admin_bar_front' => 'TRUE',
            'wp_user_level' => '0',
            'dismissed_wp_pointers' => '',
            'user_registration_country_1676005837' => 'US',
        ];

        foreach ($userMetaFields as $key => $value) {
            if (!empty($value)) {
                UserMeta::create([
                    'user_id' => $user->ID,
                    'meta_key' => $key,
                    'meta_value' => $value,
                ]);
            }
        }
        RegisterRequest::create([
            'user_id' => $user->ID,
            'finlicenceurl' => $request->input('finlicenceurl'),
            'tobaccolicenceurl' => $request->input('tobaccolicenceurl'),
            'taxidurl' => $request->input('taxidurl'),
            'govurl' => $request->input('govurl'),
        ]);
        UserMeta::create([
            'user_id' => $user->ID,
            'meta_key' => 'wp_capabilities',
            'meta_value' => serialize(['customer' => true]), // rollback to mm_price_2-> customer
        ]);
        UserMeta::create([
            'user_id' => $user->ID,
            'meta_key' => 'ur_user_status',
            'meta_value' => '0' // rollback to 0
        ]);

        // $token = JWTAuth::fromUser($user);

        return response()->json([
            'status' => true,
            'message' => 'Registration successful',
            // 'token' => $token,
            // 'user' => [
            //     'ID' => $user->ID,
            //     'name' => $user->user_login,
            //     'email' => $user->user_email,
            //     'capabilities' => $user->capabilities,
            //     'account_no' => $user->account,
            // ],
        ]);
    }
    public function me(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $data = [
                'ID' => $user->ID,
                'name' => $user->user_login,
                'email' => $user->user_email,
                'capabilities' => $user->capabilities,
                'account_no' => $user->account,
            ];
            return response()->json(['status' => 'success', 'data' => $data]);
        } catch (\Throwable $th) {
            return response()->json(['status' => false, 'message' => 'Session Expired! Login Again.']);
        }
    }
    public function sendResetLinkEmail(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $user = User::where('user_email', $request->email)->first();
        if (!$user) {
            return response()->json(['status' => false, 'message' => 'We cannot find a user with that email address.']);
        }
        $token = Str::random(60);
        DB::table('wp_password_resets')->updateOrInsert(
            ['email' => $request->email],
            [
                'email' => $request->email,
                'token' => Hash::make($token),
                'created_at' => Carbon::now()
            ]
        );
        Mail::send('password-reset-mail', ['token' => $token, 'email' => $user->user_email], function ($message) use ($user) {
            $message->to($user->user_email);
            $message->subject('Password Reset Link');
        });
        return response()->json(['status' => true, 'message' => 'We have emailed your password reset link!']);
    }
    public function reset(Request $request)
    {
        $request->validate([
            'user_email' => 'required|email',
            'token' => 'required',
            'password' => 'required|min:6|confirmed',
        ]);

        $resetRecord = DB::table('wp_password_resets')->where('email', $request->user_email)->first();

        if (!$resetRecord || !Hash::check($request->token, $resetRecord->token)) {
            return response()->json(['status' => false, 'message' => 'This password reset token is invalid.']);
        }

        if (Carbon::parse($resetRecord->created_at)->addMinutes(60)->isPast()) {
            return response()->json(['status' => false, 'message' => 'This password reset token has expired.']);
        }

        $user = User::where('user_email', $request->user_email)->first();

        if (!$user) {
            return response()->json(['status' => false, 'message' => 'User not found.']);
        }

        $user->update([
            'user_pass' => WpPassword::make($request->input('password')),
        ]);

        DB::table('wp_password_resets')->where('email', $request->user_email)->delete();

        return response()->json(['status' => true, 'message' => 'Your password has been reset!']);
    }
    public function adminlogin(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $data = $user->capabilities;


        foreach ($data as $key => $value) {
            if ($key == 'administrator') {
                $email = $request->json('user_email');
                $user = User::where('user_email', $email)->orWhere('user_login', $email)->first();
                if (!$user) {
                    return response()->json([
                        'status' => false,
                        'message' => 'not found',
                    ]);
                }
                // lines rollback in live mode
                if ($user->approved == "0") {
                    return response()->json([
                        'status' => false,
                        'message' => 'User Register Request Not Approved',
                    ]);
                }

                if($user->approved == "2") {
                    return response()->json([
                        'status' => false,
                        'message' => 'User Register Request Rejected',
                    ]);
                }
                $data = [
                    'ID' => $user->ID,
                    'name' => $user->user_login,
                    'email' => $user->user_email,
                    'capabilities' => $user->capabilities,
                    'account_no' => $user->account
                ];
                if ($token = JWTAuth::fromUser($user)) {
                    return response()->json([

                        'status' => true,
                        'token' => $token,
                        'data' => $data,
                    ]);
                }
            }
        }
        return response()->json([
            'status' => false,
            'message' => 'You are not allowed',
        ]);
    }

    public function users(string $value)
    {
        $data = User::where('user_login', 'LIKE', '%' . $value . '%')
            ->orWhere('user_email', 'LIKE', '%' . $value . '%')->limit(10)->get(['user_login', 'user_email']);
        return response()->json(['status' => true, 'data' => $data]);
    }
}
