<?php

namespace App\Modules\Transaction\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Modules\Transaction\Models\Transaction;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\UserReputationScore;
use Illuminate\Support\Facades\Validator;


class TransactionController extends Controller
{
    public function lookupByEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $authenticatedUser = auth()->user(); // Assuming auth is middleware protected
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        if ($user->id === $authenticatedUser->id) {
            return response()->json([
                'message' => 'You cannot send money to yourself.',
            ], 403);
        }

        if ($user->user_status === 'banned') {
            return response()->json([
                'message' => 'This user is currently banned and cannot receive money.',
            ], 403);
        }

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ]);
    }



    public function sendMoney(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'receiver_email' => 'required|email|exists:users,email',
            'amount' => 'required|numeric|min:1',
            'purpose' => 'required|in:Family and Friends,Goods and Services',
            'transfer_pin' => 'required|digits:4',
            'note' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        $sender = auth()->user();


        // if (!Hash::check($request->transfer_pin, $sender->transfer_pin)) {
        //     return response()->json(['message' => 'Invalid transfer PIN'], 403);
        // }

        $receiver = User::where('email', $request->receiver_email)->first(); // ✅ move this up
        
        if ($sender->user_status === 'banned') {
            return response()->json(['message' => 'Your account is restricted from sending money.'], 403);
        }

        if ($receiver->user_status === 'banned') {
            return response()->json(['message' => 'Receiver is currently banned and cannot accept transfers.'], 403);
        }
        $purposeMap = [
            'Family and Friends' => 'friends_family',
            'Goods and Services' => 'goods_services',
        ];

        $normalizedPurpose = $purposeMap[$request->purpose];

        $isGoods = $normalizedPurpose === 'goods_services';

        // Check local trust score before allowing
        $localScore = \App\Models\UserReputationScore::where('reporter_id', $sender->id)
            ->where('reported_id', $receiver->id)
            ->value('score');

        if ($isGoods && $localScore >= 5) {
            return response()->json([
                'message' => 'You are not allowed to send to this user via goods & services due to past disputes.',
            ], 403);
        }

        // Check global reputation score
        if ($isGoods && $receiver->risk_score >= 10) {
            return response()->json([
                'message' => 'Receiver is restricted from accepting goods & services transfers due to multiple complaints.',
            ], 403);
        }

        if ($sender->available_balance < $request->amount) {
            return response()->json(['message' => 'Insufficient Float'], 400);
        }

        if ($receiver->risk_score >= 60 && $request->amount > 5000) {
            return response()->json([
                'message' => 'Receiver is currently restricted from receiving large transfers due to dispute history.',
            ], 403);
        }

        if ($isGoods) {
            $pendingCount = Transaction::where('receiver_id', $receiver->id)
                ->where('purpose', 'goods_services')
                ->where('status', 'pending')
                ->count();

            if ($pendingCount >= 3) {
                return response()->json([
                    'message' => 'Receiver has multiple pending transactions',
                    'pending_count' => $pendingCount,
                ], 409);
            }
        }

        try {
            DB::beginTransaction();

            $transaction = Transaction::create([
                'sender_id' => $sender->id,
                'receiver_id' => $receiver->id,
                'amount' => $request->amount,
                'type' => 'send',
                'purpose' => $normalizedPurpose,
                'reference' => strtoupper(Str::random(12)),
                'note' => $request->note,
                'status' => $isGoods ? 'pending' : 'completed',
                'processed_at' => $isGoods ? null : now(),
                'scheduled_release_at' => $isGoods ? now()->addMinutes(20) : null,
            ]);

            $sender->available_balance -= $request->amount;
            $sender->save();

            if (!$isGoods) {
                $receiver->balance += $request->amount;
                $receiver->available_balance += $request->amount;
                $receiver->save();
            }

            DB::commit();
            return response()->json(['message' => 'Transfer successful', 'transaction' => $transaction]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Something went wrong', 'error' => $e->getMessage()], 500);
        }
    }


    public function requestMoney(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'receiver_email' => 'required|email|exists:users,email',
            'amount' => 'required|numeric|min:1',
            'note' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $sender = auth()->user(); // requester
        $receiver = User::where('email', $request->receiver_email)->first();

        if ($sender->user_status === 'banned') {
            return response()->json(['message' => 'Your account is restricted from sending money.'], 403);
        }

        if ($receiver->user_status === 'banned') {
            return response()->json(['message' => 'Receiver is currently banned and cannot accept transfers.'], 403);
        }


        try {
            DB::beginTransaction();

            $transaction = Transaction::create([
                'sender_id' => $sender->id,
                'receiver_id' => $receiver->id,
                'amount' => $request->amount,
                'type' => 'request',
                'purpose' => 'friends_family',
                'reference' => strtoupper(Str::random(12)),
                'note' => $request->note,
                'status' => 'pending',
            ]);

            DB::commit();
            return response()->json(['message' => 'Request sent', 'transaction' => $transaction]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Something went wrong', 'error' => $e->getMessage()], 500);
        }
    }

    public function respondToRequest(Request $request, $id)
    {
        $request->validate([
            'action' => 'required|in:accept,reject',
            'transfer_pin' => 'required_if:action,accept|digits:6',
        ]);

        $user = auth()->user();

        $transaction = Transaction::where('id', $id)
            ->where('type', 'request')
            ->where('receiver_id', $user->id)
            ->where('status', 'pending')
            ->firstOrFail();

        if ($request->action === 'reject') {
            $transaction->status = 'failed';
            $transaction->save();
            return response()->json(['message' => 'Request rejected']);
        }

        if (!Hash::check($request->transfer_pin, $user->transfer_pin)) {
            return response()->json(['message' => 'Invalid transfer PIN'], 403);
        }

        if ($user->available_balance < $transaction->amount) {
            return response()->json(['message' => 'Insufficient balance'], 400);
        }

        try {
            DB::beginTransaction();

            $user->available_balance -= $transaction->amount;
            $user->save();

            $sender = $transaction->sender;
            $sender->balance += $transaction->amount;
            $sender->available_balance += $transaction->amount;
            $sender->save();

            $transaction->status = 'completed';
            $transaction->processed_at = now();
            $transaction->save();

            DB::commit();
            return response()->json(['message' => 'Request accepted and paid']);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Something went wrong', 'error' => $e->getMessage()], 500);
        }
    }


    public function openDispute(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'evidence' => 'nullable|image|max:2048', // Max 2MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        $user = auth()->user();

        $transaction = Transaction::where('id', $id)
            ->where('sender_id', $user->id)
            ->where('purpose', 'goods_services')
            ->where('status', 'pending')
            ->where('disputed', false)
            ->where('scheduled_release_at', '>', now())
            ->first();

        if (!$transaction) {
            return response()->json([
                'message' => 'Cannot open dispute. Transaction already processed or not eligible.',
            ], 400);
        }

        $receiver = $transaction->receiver;

        // Save evidence if available
        $evidencePath = null;
        if ($request->hasFile('evidence')) {
            $evidencePath = $request->file('evidence')->store('dispute_evidence', 'public');
            $transaction->dispute_evidence = $evidencePath; // Ensure this column exists
        }

        try {
            DB::beginTransaction();

            // Refund sender
            $user->balance += $transaction->amount;
            $user->available_balance += $transaction->amount;
            $user->save();

            // Deduct receiver (balance and available_balance)
            if ($receiver->balance < $transaction->amount || $receiver->available_balance < $transaction->amount) {
                DB::rollBack();
                return response()->json([
                    'message' => 'Receiver does not have enough funds to reverse the transaction.',
                ], 409);
            }

            $receiver->balance -= $transaction->amount;
            $receiver->available_balance -= $transaction->amount;
            $receiver->save();

            // Update transaction
            $transaction->disputed = true;
            $transaction->status = 'disputed';
            $transaction->save();

            // Update user-to-user reputation score
            $existingScore = \App\Models\UserReputationScore::firstOrNew([
                'reporter_id' => $user->id,
                'reported_id' => $receiver->id,
            ]);
            $existingScore->score += 1;
            $existingScore->save();

            // Increase receiver's global risk score
            $receiver->risk_score += 1;
            $receiver->save();

            DB::commit();

            return response()->json([
                'message' => 'Dispute successful. Refund processed.',
                'refund_amount' => $transaction->amount,
                'receiver_risk_score' => $receiver->risk_score,
                'trust_score_with_receiver' => $existingScore->score,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error processing dispute.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


   public function history(Request $request)
{
    $user = auth()->user();

    if ($user->user_status === 'banned') {
        return response()->json(['message' => 'Your account is restricted from sending money.'], 403);
    }

    $query = \App\Modules\Transaction\Models\Transaction::with(['sender:id,name', 'receiver:id,name'])
        ->where(function ($q) use ($user) {
            $q->where('sender_id', $user->id)
              ->orWhere('receiver_id', $user->id);
        })
        ->latest();

    if ($request->has('limit')) {
        $transactions = $query->limit((int) $request->limit)->get();
    } else {
        $transactions = $query->paginate($request->get('per_page', 20));
    }

    $purposeLabels = [
        'friends_family' => 'Family & Friends',
        'goods_services' => 'Goods & Services',
    ];

    $currencySymbol = '₦';

    $mapped = $transactions->map(function ($tx) use ($user, $purposeLabels, $currencySymbol) {
        $isSender = $tx->sender_id === $user->id;
        $amountSign = $isSender ? '-' : '+';
        $title = $isSender ? 'Payment sent' : 'Payment received';

        return [
            // Raw DB fields for Dart model
            'id' => $tx->id,
            'sender_id' => $tx->sender_id,
            'receiver_id' => $tx->receiver_id,
            'amount' => $tx->amount,
            'type' => $isSender ? 'send' : 'request', // or your real type column
            'purpose' => $tx->purpose,
            'reference' => $tx->reference,
            'note' => $tx->note,
            'status' => $tx->status,
            'disputed' => (bool) $tx->disputed,
            'processed_at' => $tx->processed_at,
            'scheduled_release_at' => $tx->scheduled_release_at,
            'created_at' => $tx->created_at,
            'updated_at' => $tx->updated_at,

            // UI helper fields for direct rendering
            'title' => $title,
            'subtitle' => $isSender ? ($tx->receiver->name ?? 'Unknown') : ($tx->sender->name ?? 'Unknown'),
            'typeLabel' => $purposeLabels[$tx->purpose] ?? 'Other',
            'amountFormatted' => $amountSign . $currencySymbol . number_format($tx->amount, 2),
            'dateTime' => $tx->created_at->format('d/m/Y, H:i:s'),
            'total' => $currencySymbol . number_format($user->balance ?? 0, 2),
        ];
    });

    return response()->json([
        'data' => $mapped,
        'meta' => [
            'total' => $transactions instanceof \Illuminate\Pagination\AbstractPaginator
                ? $transactions->total()
                : $mapped->count(),
        ],
    ]);
}



}
