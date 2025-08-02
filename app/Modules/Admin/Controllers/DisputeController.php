<?php
namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Transaction\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DisputeController extends Controller
{
    public function index()
    {
        $disputes = Transaction::with(['sender', 'receiver'])
            ->where([
                ['status', '=', 'disputed'],
                ['disputed', '=', true],
            ])

            ->latest()
            ->get();

        return response()->json($disputes);
    }

    public function resolve(Request $request, $id)
    {
        $request->validate([
            'action' => 'required|in:return_to_sender,credit_receiver',
        ]);

        $transaction = Transaction::where('id', $id)
            ->where('status', 'disputed')
            ->firstOrFail();

        DB::beginTransaction();
        try {
            $user = $request->user(); // the admin

            if ($request->action === 'return_to_sender') {
                $transaction->sender->available_balance += $transaction->amount;
                $transaction->sender->save();
                $transaction->status = 'refunded';
            } else {
                $transaction->receiver->balance += $transaction->amount;
                $transaction->receiver->available_balance += $transaction->amount;
                $transaction->receiver->save();
                $transaction->status = 'completed';
            }

            $transaction->processed_at = now();
            $transaction->save();

            DB::commit();
            return response()->json(['message' => 'Dispute resolved successfully']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error resolving dispute'], 500);
        }
    }
}
