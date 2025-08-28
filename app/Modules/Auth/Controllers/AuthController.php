<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Str;
use App\Models\RefreshToken;
use Illuminate\Support\Facades\Log;
use App\Modules\Auth\Rules\StrongPin;
use App\Support\SessionRotation; // add this at the top
use App\Services\Automation\FCMService;




class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()], 422);
        }

        DB::beginTransaction();

        try {
            $otp = random_int(100000, 999999);

            $user = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => bcrypt($request->password),
                'otp_pin'  => $otp,
            ]);

            $this->sendOtpEmail($user->email, $otp);

            DB::commit();

            return response()->json([
                'message' => 'Account created. Check your email for the verification code.',
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Registration failed.', 'error' => $e->getMessage()], 500);
        }
    }

    public function resendOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || $user->otp_pin === null) {
            return response()->json(['message' => 'Email already verified.'], 400);
        }

        DB::beginTransaction();

        try {
            $otp = random_int(100000, 999999);
            $user->otp_pin = $otp;
            $user->save();

            $this->sendOtpEmail($user->email, $otp);

            DB::commit();

            return response()->json(['message' => 'OTP resent. Check your email.']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to resend OTP.', 'error' => $e->getMessage()], 500);
        }
    }

    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Invalid login credentials'], 401);
        }

        // ❌ Not verified yet
        if (!$user->is_verified) {
            return response()->json([
                'status' => 'not_verified',
                'message' => 'Your account is not verified. Please complete verification first.',
            ], 403);
        }

        // 🟡 Verified but PIN not set yet
        if (is_null($user->pin)) {
            return response()->json([
                'status' => 'set_pin',
                'email' => $user->email,
                'message' => 'Please set your 6-digit PIN to proceed.',
            ], 200);
        }

        // ✅ Verified AND PIN already set → Generate OTP
        $otp = rand(100000, 999999);
        $user->otp_pin = $otp;
        $this->sendOtpEmail($user->email, $otp);
        $user->save();

        return response()->json([
            'status' => 'otp_required',
            'email' => $user->email,
            'message' => 'OTP sent. Please verify to complete login.',
        ], 200);
    }

    public function oldsetPin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'pin' => ['required', 'digits:6', new StrongPin()],
        ]);

        if ($validator->fails()) {
            $errorMessages = $validator->errors()->all(); // Get flat list of messages

            return response()->json([
                'message' => $errorMessages[0] ?? 'Validation failed', // Return first error
            ], 422);
        }


        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        if ($user->pin) {
            return response()->json(['message' => 'PIN already set'], 400);
        }

        $user->pin = bcrypt($request->pin);
        $user->save();

        // ✅ Generate JWT and refresh token after setting PIN
        $token = JWTAuth::fromUser($user);

        $refreshToken = Str::random(60);
        $user->refreshTokens()->create([
            'token' => hash('sha256', $refreshToken),
            'expires_at' => now()->addDays(30),
        ]);

        return response()->json([
            'message' => 'PIN set successfully',
            'user' => $user,
            'token' => $token,
            'refresh_token' => $refreshToken,
        ], 200);
    }

    public function setPin(Request $request, SessionRotation $sessionRotation)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'pin'   => ['required', 'digits_between:4,6', new StrongPin()],
            'device_id'   => 'required|string',
            'device_name' => 'nullable|string',
            'platform'    => 'nullable|string',
            'fcm_token'   => 'nullable|string',
        ]);

        if ($validator->fails()) {
            $errorMessages = $validator->errors()->all();

            return response()->json([
                'message' => $errorMessages[0] ?? 'Validation failed',
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        if ($user->pin) {
            return response()->json(['message' => 'PIN already set'], 400);
        }

        $user->pin = bcrypt($request->pin);
        $user->save();

        return DB::transaction(function () use ($user, $request, $sessionRotation) {
            // Generate access + refresh tokens
            $accessToken  = JWTAuth::fromUser($user);
            $refreshToken = Str::random(60);

            $newToken = $user->refreshTokens()->create([
                'token'       => hash('sha256', $refreshToken),
                'expires_at'  => now()->addDays(30),
                'device_id'   => $request->input('device_id'),
                'device_name' => $request->input('device_name'),
                'platform'    => $request->input('platform'),
                'fcm_token'   => $request->input('fcm_token'),
            ]);

            // Revoke all other sessions except this one
            $sessionRotation->pushAndRevokeOldSessions($user->id, $newToken->id);

            return response()->json([
                'message'       => 'PIN set successfully',
                'user'          => $user,
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
            ], 200);
        });
    }






    public function oldverifyEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'otp_pin' => 'required|digits:6',
        ]);

        $user = User::where('email', $request->email)
            ->where('otp_pin', $request->otp_pin)
            ->first();

        if (!$user) {
            return response()->json(['message' => 'Invalid verification code'], 400);
        }

        // Clear the OTP
        $user->is_verified = 1;
        $user->otp_pin = null;
        $user->save();

        // Generate JWT token
        if (!$token = JWTAuth::fromUser($user)) {
            return response()->json(['message' => 'Unable to generate token.'], 500);
        }

        $refreshToken = Str::random(60);
        $user->refreshTokens()->create([
            'token' => hash('sha256', $refreshToken),
            'expires_at' => now()->addDays(30),
        ]);

        return response()->json([
            'token' => $token,
            'refresh_token' => $refreshToken,
            'user' => $user,
        ]);
    }
    public function verifyEmail(Request $request, SessionRotation $rotator)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'otp_pin' => 'required|digits:6',
            'device_id' => 'required|string',
            'device_name' => 'required|string',
            'platform' => 'required|string',
            'fcm_token' => 'nullable|string',
        ]);

        $user = User::where('email', $request->email)
            ->where('otp_pin', $request->otp_pin)
            ->first();

        if (!$user) {
            return response()->json(['message' => 'Invalid verification code'], 400);
        }

        // ✅ mark verified
        $user->is_verified = 1;
        $user->otp_pin = null;
        $user->save();

        // 🔄 revoke old sessions + send push logout
        $rotator->pushAndRevokeOldSessions($user->id);

        // ✅ issue new tokens
        $accessToken = JWTAuth::fromUser($user);
        $plainRefresh = Str::random(60);

        $user->refreshTokens()->create([
            'token' => hash('sha256', $plainRefresh),
            'expires_at' => now()->addDays(30),
            'device_id' => $request->device_id,
            'device_name' => $request->device_name,
            'platform' => $request->platform,
            'fcm_token' => $request->fcm_token,
        ]);

        return response()->json([
            'access_token' => $accessToken,
            'refresh_token' => $plainRefresh,
            'user' => $user,
        ]);
    }


    public function oldpinLogin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'pin' => 'required|string|min:4|max:6', // You can adjust min/max as needed
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid login credentials',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->pin, $user->pin)) {
            return response()->json(['message' => 'Invalid login credentials'], 401);
        }

        if ($user->otp_pin) {
            return response()->json([
                'message' => 'Email not verified. Please check your email for the code.',
            ], 403);
        }

        $token = JWTAuth::fromUser($user);

        $refreshToken = Str::random(60);

        $user->refreshTokens()->create([
            'token' => hash('sha256', $refreshToken),
            'expires_at' => now()->addDays(30),
        ]);

        return response()->json([
            'token' => $token,
            'refresh_token' => $refreshToken,
            'user' => $user,
        ]);
    }


    public function pinLogin(Request $request, SessionRotation $sessionRotation)
    {
        $validator = Validator::make($request->all(), [
            'email'       => 'required|email|exists:users,email',
            'pin'         => 'required|string|min:4|max:6',
            'device_id'   => 'required|string',
            'device_name' => 'nullable|string',
            'platform'    => 'nullable|string',
            'fcm_token'   => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid login credentials',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->pin, $user->pin)) {
            return response()->json(['message' => 'Invalid login credentials'], 401);
        }

        if ($user->otp_pin) {
            return response()->json([
                'message' => 'Email not verified. Please check your email for the code.',
            ], 403);
        }

        return DB::transaction(function () use ($user, $request, $sessionRotation) {
            $accessToken  = JWTAuth::fromUser($user);
            $refreshToken = Str::random(60);

            $existingSession = $user->refreshTokens()
                ->active()
                ->where('device_id', $request->device_id)
                ->first();

            if ($existingSession) {
                $existingSession->update([
                    'token'       => hash('sha256', $refreshToken),
                    'expires_at'  => now()->addDays(30),
                    'device_name' => $request->input('device_name'),
                    'platform'    => $request->input('platform'),
                    'fcm_token'   => $request->input('fcm_token'),
                    'revoked_at'  => null,
                ]);

                $newToken = $existingSession;
            } else {
                $newToken = $user->refreshTokens()->create([
                    'token'       => hash('sha256', $refreshToken),
                    'expires_at'  => now()->addDays(30),
                    'device_id'   => $request->input('device_id'),
                    'device_name' => $request->input('device_name'),
                    'platform'    => $request->input('platform'),
                    'fcm_token'   => $request->input('fcm_token'),
                ]);

                // Revoke all other sessions for single-device enforcement
                $sessionRotation->pushAndRevokeOldSessions($user->id, $newToken->id);
            }

            return response()->json([
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'user'          => $user,
            ]);
        });
    }

    public function refreshWithToken(Request $request, SessionRotation $sessionRotation)
    {
        $refreshToken = $request->input('refresh_token');

        if (!$refreshToken) {
            return response()->json(['message' => 'Refresh token is required'], 422);
        }

        $hashed = hash('sha256', $refreshToken);

        $storedToken = RefreshToken::with('user')
            ->where('token', $hashed)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$storedToken || !$storedToken->user) {
            return response()->json(['message' => 'Invalid or expired refresh token'], 401);
        }

        $user = $storedToken->user;

        return DB::transaction(function () use ($user, $storedToken, $request, $sessionRotation) {
            $accessToken  = JWTAuth::fromUser($user);
            $newRefreshToken = Str::random(60);

            $newToken = $user->refreshTokens()->create([
                'token'       => hash('sha256', $newRefreshToken),
                'expires_at'  => now()->addDays(30),
                'device_id'   => $storedToken->device_id,
                'device_name' => $storedToken->device_name,
                'platform'    => $storedToken->platform,
                'fcm_token'   => $request->input('fcm_token', $storedToken->fcm_token),
            ]);

            // Revoke all other sessions except this one
            $sessionRotation->pushAndRevokeOldSessions($user->id, $newToken->id);

            // Revoke the old token
            $storedToken->update(['revoked_at' => now()]);

            return response()->json([
                'access_token'  => $accessToken,
                'refresh_token' => $newRefreshToken,
            ]);
        });
    }


    public function oldrefreshWithToken(Request $request)
    {
        $refreshToken = $request->input('refresh_token');

        if (!$refreshToken) {
            return response()->json(['message' => 'Refresh token is required'], 422);
        }

        $hashed = hash('sha256', $refreshToken);

        $storedToken = RefreshToken::with('user')
            ->where('token', $hashed)
            ->where('expires_at', '>', now())
            ->first();

        if (!$storedToken || !$storedToken->user) {
            return response()->json(['message' => 'Invalid or expired refresh token'], 401);
        }

        $user = $storedToken->user;

        // Invalidate the old refresh token
        $storedToken->delete();

        // Create a new refresh token
        $newRefreshToken = Str::random(60);

        $user->refreshTokens()->create([
            'token' => hash('sha256', $newRefreshToken),
            'refresh_token' => $newRefreshToken,
            'expires_at' => now()->addDays(30),
        ]);

        // Issue a new access token
        $newAccessToken = JWTAuth::fromUser($user);

        return response()->json([
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken,
        ]);
    }





    public function logout()
    {
        auth()->logout();
        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me()
    {
        return response()->json(auth()->user());
    }

    public function setTransferPin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'transfer_pin' => ['required', 'digits:4', new StrongPin()],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422); // 422 Unprocessable Entity is the standard for validation errors
        }

        
        // Reject weak PINs
        if ($this->isWeakPin($request->pin)) {
            return response()->json([
                'message' => 'The PIN provided is too weak. Please choose a more secure PIN.',
            ], 422);
        }
        $user = auth()->user();

        if ($user->transfer_pin) {
            return response()->json(['message' => 'Transfer PIN already set'], 400);
        }

        $user->transfer_pin = bcrypt($request->transfer_pin);
        $user->save();

        return response()->json(['message' => 'Transfer PIN set successfully']);
    }

    protected function sendOtpEmail($email, $otp)
    {
        // Simulate (for now) — replace with queued job in production
        logger("Sending OTP {$otp} to {$email}");

        // Real email (enabled when MAIL config is valid)
        Mail::raw("Your verification code is: {$otp}", function ($message) use ($email) {
            $message->to($email)
                ->subject('Your Verification Code');
        });
    }
}
