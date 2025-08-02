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

class TransactionController extends Controller
{
    public function sendMoney(Request $request)
    {
        $request->validate([
            'receiver_email' => 'required|email|exists:users,email',
            'amount' => 'required|numeric|min:1',
            'purpose' => 'required|in:friends_family,goods_services',
            'transfer_pin' => 'required|digits:6',
            'note' => 'nullable|string|max:255',
        ]);

        $sender = auth()->user();

        if (!Hash::check($request->transfer_pin, $sender->transfer_pin)) {
            return response()->json(['message' => 'Invalid transfer PIN'], 403);
        }

        // Check local trust score before allowing
        $localScore = UserReputationScore::where('reporter_id', $sender->id)
            ->where('reported_id', $receiver->id)
            ->value('score');

        if ($request->purpose === 'goods_services' && $localScore >= 5) {
            return response()->json([
                'message' => 'You are not allowed to send to this user via goods & services due to past disputes.',
            ], 403);
        }

        // Check global reputation score
        if ($request->purpose === 'goods_services' && $receiver->risk_score >= 10) {
            return response()->json([
                'message' => 'Receiver is restricted from accepting goods & services transfers due to multiple complaints.',
            ], 403);
        }

        if ($sender->available_balance < $request->amount) {
            return response()->json(['message' => 'Insufficient available balance'], 400);
        }

        $receiver = User::where('email', $request->receiver_email)->first();
        $isGoods = $request->purpose === 'goods_services';

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
                'purpose' => $request->purpose,
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
        $request->validate([
            'receiver_email' => 'required|email|exists:users,email',
            'amount' => 'required|numeric|min:1',
            'note' => 'nullable|string|max:255',
        ]);

        $sender = auth()->user(); // requester
        $receiver = User::where('email', $request->receiver_email)->first();

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
        $request->validate([
            'evidence' => 'nullable|image|max:2048', // Optional image
        ]);

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


    public function history()
    {
        $user = auth()->user();

        $transactions = \App\Modules\Transaction\Models\Transaction::where(function ($query) use ($user) {
            $query->where('sender_id', $user->id)
                ->orWhere('receiver_id', $user->id);
        })->latest()->get();

        return response()->json($transactions);
    }

}
