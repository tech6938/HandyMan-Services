<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\BookingModule\Entities\BookingDetailsAmount;
use Modules\BookingModule\Entities\BookingPartialPayment;
use Modules\BusinessSettingsModule\Entities\PackageSubscriber;
use Modules\PaymentModule\Entities\Bonus;
use Modules\ServiceManagement\Entities\ServiceCommission;
use Modules\TransactionModule\Entities\Account;
use Modules\TransactionModule\Entities\LoyaltyPointTransaction;
use Modules\TransactionModule\Entities\Transaction;
use Modules\UserManagement\Entities\User;


//============ Booking Place ============
if (!function_exists('placeBookingTransactionForDigitalPayment')) {
    function placeBookingTransactionForDigitalPayment($booking): void
    {
        if ($booking['payment_method'] != 'cash_after_service') {
            $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
            DB::transaction(function () use ($booking, $admin_user_id) {
                //Admin account update
                $account = Account::where('user_id', $admin_user_id)->first();
                $account->balance_pending += $booking['total_booking_amount'];
                $account->save();

                //Admin transaction
                Transaction::create([
                    'ref_trx_id' => null,
                    'booking_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['booking_amount'],
                    'debit' => 0,
                    'credit' => $booking['total_booking_amount'],
                    'balance' => $account->balance_pending,
                    'from_user_id' => $booking->customer_id,
                    'to_user_id' => $admin_user_id,
                    'from_user_account' => null,
                    'to_user_account' => ACCOUNT_STATES[0]['value'],
                    'is_guest' => $booking->is_guest
                ]);
            });
        }
    }
}

if (!function_exists('placeBookingRepeatTransactionForDigitalPayment')) {
    function placeBookingRepeatTransactionForDigitalPayment($booking): void
    {
        if ($booking['payment_method'] != 'cash_after_service') {
            $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
            DB::transaction(function () use ($booking, $admin_user_id) {
                //Admin account update
                $account = Account::where('user_id', $admin_user_id)->first();
                $account->balance_pending += $booking['total_booking_amount'];
                $account->save();

                //Admin transaction
                Transaction::create([
                    'ref_trx_id' => null,
                    'booking_id' => 0,
                    'booking_repeat_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['booking_amount'],
                    'debit' => 0,
                    'credit' => $booking['total_booking_amount'],
                    'balance' => $account->balance_pending,
                    'from_user_id' => $booking?->booking?->customer_id,
                    'to_user_id' => $admin_user_id,
                    'from_user_account' => null,
                    'to_user_account' => ACCOUNT_STATES[0]['value'],
                    'is_guest' => $booking->is_guest
                ]);
            });
        }
    }
}

if (!function_exists('placeBookingTransactionForPartialCas')) {
    /**
     * Admin (+balance_pending)
     * Customer (-wallet_balance)
     * @param $booking
     * @return void
     */
    function placeBookingTransactionForPartialCas($booking): void
    {
        $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
        $user_wallet_balance = User::find($booking->customer_id)?->wallet_balance;
        $paid_amount = $user_wallet_balance;

        DB::transaction(function () use ($booking, $admin_user_id, $paid_amount) {
            /** wallet partial */

            //Admin transaction
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->balance_pending += $paid_amount;
            $account->save();

            Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => $booking['id'],
                'trx_type' => TRX_TYPE['booking_amount'],
                'debit' => 0,
                'credit' => $paid_amount,
                'balance' => $account->balance_pending,
                'from_user_id' => $booking->customer_id,
                'to_user_id' => $admin_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[0]['value']
            ]);

            //customer transaction (wallet)
            $user = User::find($booking['customer_id']);
            if ($user->wallet_balance >= $paid_amount) $user->wallet_balance -= $paid_amount;
            $user->save();

            Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => $booking['id'],
                'trx_type' => WALLET_TRX_TYPE['wallet_payment'],
                'debit' => $paid_amount,
                'credit' => 0,
                'balance' => $user->wallet_balance,
                'from_user_id' => $booking->customer_id,
                'to_user_id' => $booking->customer_id,
                'from_user_account' => null,
                'to_user_account' => 'user_wallet'
            ]);
        });
    }
}

if (!function_exists('placeBookingTransactionForPartialDigital')) {
    /**
     * Admin (+balance_pending) [wallet payment]
     * Customer (-wallet_balance) [wallet payment]
     * Admin (+balance_pending) [digital payment]
     * @param $booking
     * @return void
     */
    function placeBookingTransactionForPartialDigital($booking): void
    {
        $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
        $user_wallet_balance = User::find($booking->customer_id)?->wallet_balance;

        $paid_amount = $user_wallet_balance;
        $due_amount =  $booking['total_booking_amount'] - $paid_amount;

        DB::transaction(function () use ($booking, $admin_user_id, $paid_amount, $due_amount) {
            /** wallet partial */
            //Admin transaction
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->balance_pending += $paid_amount;
            $account->save();

            Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => $booking['id'],
                'trx_type' => TRX_TYPE['booking_amount'],
                'debit' => 0,
                'credit' => $paid_amount,
                'balance' => $account->balance_pending,
                'from_user_id' => $booking->customer_id,
                'to_user_id' => $admin_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[0]['value']
            ]);

            //customer transaction (wallet)
            $user = User::find($booking['customer_id']);
            if ($user->wallet_balance >= $paid_amount) $user->wallet_balance -= $paid_amount;
            $user->save();

            Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => $booking['id'],
                'trx_type' => WALLET_TRX_TYPE['wallet_payment'],
                'debit' => $paid_amount,
                'credit' => 0,
                'balance' => $user->wallet_balance,
                'from_user_id' => $booking->customer_id,
                'to_user_id' => $booking->customer_id,
                'from_user_account' => null,
                'to_user_account' => 'user_wallet'
            ]);

            /** CAS partial */
            //Admin transaction
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->balance_pending += $due_amount;
            $account->save();

            Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => $booking['id'],
                'trx_type' => TRX_TYPE['booking_amount'],
                'debit' => 0,
                'credit' => $due_amount,
                'balance' => $account->balance_pending,
                'from_user_id' => $booking->customer_id,
                'to_user_id' => $admin_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[0]['value'],
                'is_guest' => $booking->is_guest
            ]);
        });
    }
}

if (!function_exists('placeBookingTransactionForWalletPayment')) {
    function placeBookingTransactionForWalletPayment($booking): void
    {
        $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
        DB::transaction(function () use ($booking, $admin_user_id) {
            //Admin account update
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->balance_pending += $booking['total_booking_amount'];
            $account->save();

            //Admin transaction
            Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => $booking['id'],
                'trx_type' => TRX_TYPE['booking_amount'],
                'debit' => 0,
                'credit' => $booking['total_booking_amount'],
                'balance' => $account->balance_pending,
                'from_user_id' => $booking->customer_id,
                'to_user_id' => $admin_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[0]['value']
            ]);



            //Customer wallet update
            $user = User::find($booking['customer_id']);
            if ($user->wallet_balance >= $booking['total_booking_amount']) {
                $user->wallet_balance -= $booking['total_booking_amount'];
            }
            $user->save();

            //customer transaction (wallet)
            Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => $booking['id'],
                'trx_type' => WALLET_TRX_TYPE['wallet_payment'],
                'debit' => $booking['total_booking_amount'],
                'credit' => 0,
                'balance' => $user->wallet_balance,
                'from_user_id' => $booking->customer_id,
                'to_user_id' => $booking->customer_id,
                'from_user_account' => null,
                'to_user_account' => 'user_wallet'
            ]);
        });
    }
}


//============ Booking Edit ============
if (!function_exists('removeBookingServiceTransactionForDigitalPayment')) {
    /**
     * Admin -$amount [balance_pending]
     * Customer +$amount [wallet_balance]
     * @param $booking
     * @param $removed_total
     * @return void
     */
    function removeBookingServiceTransactionForDigitalPayment($booking, $removed_total): void
    {
        $amount = 0;

        //refund amount calculation
        if (($booking->booking_partial_payments->isEmpty() && $booking['payment_method'] != 'cash_after_service') || $booking->booking_partial_payments->isNotEmpty()) {

            if ($booking->booking_partial_payments->isEmpty()) { //not partial
                $amount = $removed_total;
            } elseif ($booking->booking_partial_payments->isNotEmpty()) { //partial
                //(wallet + digital/offline) or (wallet + CAS)
                $paid_amount = $booking->booking_partial_payments->sum('paid_amount');

                if (($removed_total - $paid_amount) < 0) { //paid more than booking amount
                    $amount = abs($removed_total - $paid_amount);
                }
            }
        }

        if ($amount > 0) {
            $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
            $primary_transaction = Transaction::where('booking_id', $booking['id'])->whereNull('ref_trx_id')->first()?->id;

            DB::transaction(function () use ($booking, $admin_user_id, $amount, $primary_transaction) {
                //Admin transaction
                $account = Account::where('user_id', $admin_user_id)->first();
                if ($account->balance_pending >= $amount) {
                    $account->balance_pending -= $amount;
                }
                $account->save();

                $primary_transaction = Transaction::create([
                    'ref_trx_id' => $primary_transaction ?? null,
                    'booking_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['booking_amount'],
                    'debit' => $amount,
                    'credit' => 0,
                    'balance' => $account->balance_pending,
                    'from_user_id' => $admin_user_id,
                    'to_user_id' => $admin_user_id,
                    'from_user_account' => null,
                    'to_user_account' => ACCOUNT_STATES[0]['value']
                ]);

                //customer transaction
                $user = User::find($booking['customer_id']);
                $user->wallet_balance += $amount;
                $user->save();

                Transaction::create([
                    'ref_trx_id' => $primary_transaction->id,
                    'booking_id' => $booking['id'],
                    'trx_type' => WALLET_TRX_TYPE['booking_refund'],
                    'debit' => 0,
                    'credit' => $amount,
                    'balance' => $user->wallet_balance,
                    'from_user_id' => $admin_user_id,
                    'to_user_id' => $booking['customer_id'],
                    'from_user_account' => 'wallet_balance',
                    'to_user_account' => null
                ]);
            });
        }
    }
}


//============ After Complete Booking ============
if (!function_exists('getBookingLedgerDetailsAmounts')) {
    function getBookingLedgerDetailsAmounts($booking)
    {
        if (isset($booking->booking_id)) {
            return BookingDetailsAmount::where('booking_repeat_id', $booking->id)->get();
        }

        return BookingDetailsAmount::where('booking_id', $booking->id)->get();
    }
}

if (!function_exists('getBookingLedgerSnapshot')) {
    function getBookingLedgerSnapshot($booking): array
    {
        $booking_details_amounts = getBookingLedgerDetailsAmounts($booking);

        $admin_commission = 0;
        $provider_earning = 0;
        $promotional_cost_by_admin = 0;
        $promotional_cost_by_provider = 0;

        foreach ($booking_details_amounts as $booking_details_amount) {
            $admin_commission += (float) $booking_details_amount->admin_commission;
            $provider_earning += (float) $booking_details_amount->provider_earning;
            $promotional_cost_by_admin += (float) $booking_details_amount->discount_by_admin
                + (float) $booking_details_amount->coupon_discount_by_admin
                + (float) $booking_details_amount->campaign_discount_by_admin;
            $promotional_cost_by_provider += (float) $booking_details_amount->discount_by_provider
                + (float) $booking_details_amount->coupon_discount_by_provider
                + (float) $booking_details_amount->campaign_discount_by_provider;
        }

        return [
            'admin_commission' => round($admin_commission, 2),
            'provider_earning' => round($provider_earning, 2),
            'promotional_cost_by_admin' => round($promotional_cost_by_admin, 2),
            'promotional_cost_by_provider' => round($promotional_cost_by_provider, 2),
        ];
    }
}

if (!function_exists('normalizeAccountReceivablePayable')) {
    function normalizeAccountReceivablePayable(Account $account): void
    {
        $net = $account->account_receivable - $account->account_payable;
        if ($net >= 0) {
            $account->account_receivable = $net;
            $account->account_payable = 0;
        } else {
            $account->account_receivable = 0;
            $account->account_payable = abs($net);
        }
        $account->save();
    }
}

if (!function_exists('completeBookingTransactionForDigitalPayment')) {
    function completeBookingTransactionForDigitalPayment($booking): void
    {
        $ledgerSnapshot = getBookingLedgerSnapshot($booking);
        $admin_commission = $ledgerSnapshot['admin_commission'];
        $provider_earning = $ledgerSnapshot['provider_earning'];
        $promotional_cost_by_admin = $ledgerSnapshot['promotional_cost_by_admin'];
        $promotional_cost_by_provider = $ledgerSnapshot['promotional_cost_by_provider'];

        //user ids (from/to)
        $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
        $provider_user_id = get_user_id($booking['provider_id'], PROVIDER_USER_TYPES[0]);

        DB::transaction(function () use ($booking, $admin_user_id, $provider_user_id, $admin_commission, $provider_earning, $promotional_cost_by_admin, $promotional_cost_by_provider) {

            $account = Account::where('user_id', $admin_user_id)->first();
            $account->balance_pending -= $booking['total_booking_amount'];
            $account->save();

            //Admin transaction (-pending)
            $primary_transaction = Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => $booking['id'],
                'trx_type' => TRX_TYPE['pending_amount'],
                'debit' => $booking['total_booking_amount'],
                'credit' => 0,
                'balance' => $account->balance_pending,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => ACCOUNT_STATES[0]['value'],
                'to_user_account' => null
            ]);

            //Provider transactions (+receivable)
            $account = Account::where('user_id', $provider_user_id)->first();
            $account->account_receivable += $provider_earning;
            $account->save();

            Transaction::create([
                'ref_trx_id' => $primary_transaction['id'],
                'booking_id' => $booking['id'],
                'trx_type' => TRX_TYPE['receivable_amount'],
                'debit' => 0,
                'credit' => $provider_earning,
                'balance' => $account->account_receivable,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[3]['value']
            ]);

            if ($admin_commission > 0) {
                //Admin transactions for commission (+received)
                $account = Account::where('user_id', $admin_user_id)->first();
                $account->received_balance += $admin_commission;
                $account->save();

                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['received_commission'],
                    'debit' => 0,
                    'credit' => $admin_commission,
                    'balance' => $account->received_balance,
                    'from_user_id' => $admin_user_id,
                    'to_user_id' => $admin_user_id,
                    'from_user_account' => ACCOUNT_STATES[1]['value'],
                    'to_user_account' => null
                ]);
            }

            //admin extra fee transaction
            if ($booking['extra_fee'] > 0) {
                //Admin transactions for extra fee (+received_balance)
                $account = Account::where('user_id', $admin_user_id)->first();
                $account->received_balance += $booking['extra_fee'];
                $account->save();

                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['received_extra_fee'],
                    'debit' => 0,
                    'credit' => $booking['extra_fee'],
                    'balance' => $account->received_balance,
                    'from_user_id' => $admin_user_id,
                    'to_user_id' => $admin_user_id,
                    'from_user_account' => ACCOUNT_STATES[1]['value'],
                    'to_user_account' => null
                ]);
            }

            //Admin transactions (+payable)
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->account_payable += $provider_earning;
            $account->save();

            Transaction::create([
                'ref_trx_id' => $primary_transaction['id'],
                'booking_id' => $booking['id'],
                'trx_type' => TRX_TYPE['payable_amount'],
                'debit' => 0,
                'credit' => $provider_earning,
                'balance' => $account->account_payable,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $admin_user_id,
                'from_user_account' => ACCOUNT_STATES[2]['value'],
                'to_user_account' => null
            ]);

            //expense
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->total_expense += $promotional_cost_by_admin;
            $account->save();

            $account = Account::where('user_id', $provider_user_id)->first();
            $account->total_expense += $promotional_cost_by_provider;
            $account->save();
        });
    }
}



if (!function_exists('completeBookingTransactionForCashAfterService')) {
    function completeBookingTransactionForCashAfterService($booking): void
    {
        $ledgerSnapshot = getBookingLedgerSnapshot($booking);
        $admin_commission = $ledgerSnapshot['admin_commission'];
        $provider_earning = $ledgerSnapshot['provider_earning'];
        $promotional_cost_by_admin = $ledgerSnapshot['promotional_cost_by_admin'];
        $promotional_cost_by_provider = $ledgerSnapshot['promotional_cost_by_provider'];

        //user ids (from/to)
        $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
        $provider_user_id = get_user_id($booking['provider_id'], PROVIDER_USER_TYPES[0]);

        DB::transaction(function () use ($booking, $admin_user_id, $provider_user_id, $admin_commission, $provider_earning, $promotional_cost_by_admin, $promotional_cost_by_provider) {

            //Provider transactions
            $account = Account::where('user_id', $provider_user_id)->first();
            $account->received_balance += $provider_earning;
            $account->save();

            $primary_transaction = Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => $booking['id'],
                'trx_type' => TRX_TYPE['received_amount'],
                'debit' => 0,
                'credit' => $provider_earning,
                'balance' => $account->received_balance,
                'from_user_id' => $provider_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[1]['value']
            ]);

            if ($admin_commission > 0) {
                //Provider transactions (for commission)
                $account = Account::where('user_id', $provider_user_id)->first();
                $account->account_payable += $admin_commission;
                $account->save();

                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['payable_commission'],
                    'debit' => 0,
                    'credit' => $admin_commission,
                    'balance' => $account->account_payable,
                    'from_user_id' => $provider_user_id,
                    'to_user_id' => $provider_user_id,
                    'from_user_account' => ACCOUNT_STATES[2]['value'],
                    'to_user_account' => null
                ]);
            }

            if ($booking['extra_fee'] > 0) {
                //Provider transactions (for commission)
                $account = Account::where('user_id', $provider_user_id)->first();
                $account->account_payable += $booking['extra_fee'];
                $account->save();

                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['payable_extra_fee'],
                    'debit' => 0,
                    'credit' => $booking['extra_fee'],
                    'balance' => $account->account_payable,
                    'from_user_id' => $provider_user_id,
                    'to_user_id' => $provider_user_id,
                    'from_user_account' => ACCOUNT_STATES[2]['value'],
                    'to_user_account' => null
                ]);
            }

            if ($admin_commission > 0) {
                //Admin transactions (for commission)
                $account = Account::where('user_id', $admin_user_id)->first();
                $account->account_receivable += $admin_commission;
                $account->save();

                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['receivable_commission'],
                    'debit' => 0,
                    'credit' => $admin_commission,
                    'balance' => $account->account_receivable,
                    'from_user_id' => $admin_user_id,
                    'to_user_id' => $provider_user_id,
                    'from_user_account' => ACCOUNT_STATES[3]['value'],
                    'to_user_account' => null
                ]);
            }

            if ($booking['extra_fee']) {
                //Admin transactions (for commission)
                $account = Account::where('user_id', $admin_user_id)->first();
                $account->account_receivable += $booking['extra_fee'];
                $account->save();

                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['received_extra_fee'],
                    'debit' => 0,
                    'credit' => $booking['extra_fee'],
                    'balance' => $account->account_receivable,
                    'from_user_id' => $provider_user_id,
                    'to_user_id' => $admin_user_id,
                    'from_user_account' => ACCOUNT_STATES[3]['value'],
                    'to_user_account' => null
                ]);
            }

            //expense (admin)
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->total_expense += $promotional_cost_by_admin;
            $account->save();

            //expense (provider)
            $account = Account::where('user_id', $provider_user_id)->first();
            $account->total_expense += $promotional_cost_by_provider;
            $account->save();

            // Normalize account net positions for both admin and provider.
            $adminAccount = Account::where('user_id', $admin_user_id)->first();
            normalizeAccountReceivablePayable($adminAccount);

            $providerAccount = Account::where('user_id', $provider_user_id)->first();
            normalizeAccountReceivablePayable($providerAccount);
        });
    }
}
if (!function_exists('completeBookingRepeatTransactionForCashAfterService')) {
    function completeBookingRepeatTransactionForCashAfterService($booking): void
    {
        $ledgerSnapshot = getBookingLedgerSnapshot($booking);
        $admin_commission = $ledgerSnapshot['admin_commission'];
        $provider_earning = $ledgerSnapshot['provider_earning'];
        $promotional_cost_by_admin = $ledgerSnapshot['promotional_cost_by_admin'];
        $promotional_cost_by_provider = $ledgerSnapshot['promotional_cost_by_provider'];

        //user ids (from/to)
        $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
        $provider_user_id = get_user_id($booking['provider_id'], PROVIDER_USER_TYPES[0]);

        DB::transaction(function () use ($booking, $admin_user_id, $provider_user_id, $admin_commission, $provider_earning, $promotional_cost_by_admin, $promotional_cost_by_provider) {

            //Provider transactions
            $account = Account::where('user_id', $provider_user_id)->first();
            $account->received_balance += $provider_earning;
            $account->save();

            $primary_transaction = Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => 0,
                'booking_repeat_id' => $booking['id'],
                'trx_type' => TRX_TYPE['received_amount'],
                'debit' => 0,
                'credit' => $provider_earning,
                'balance' => $account->received_balance,
                'from_user_id' => $provider_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[1]['value']
            ]);

            if ($admin_commission > 0) {
                //Provider transactions (for commission)
                $account = Account::where('user_id', $provider_user_id)->first();
                $account->account_payable += $admin_commission;
                $account->save();

                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => 0,
                    'booking_repeat_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['payable_commission'],
                    'debit' => 0,
                    'credit' => $admin_commission,
                    'balance' => $account->account_payable,
                    'from_user_id' => $provider_user_id,
                    'to_user_id' => $provider_user_id,
                    'from_user_account' => ACCOUNT_STATES[2]['value'],
                    'to_user_account' => null
                ]);
            }

            if ($booking['extra_fee'] > 0) {
                //Provider transactions (for commission)
                $account = Account::where('user_id', $provider_user_id)->first();
                $account->account_payable += $booking['extra_fee'];
                $account->save();

                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => 0,
                    'booking_repeat_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['payable_extra_fee'],
                    'debit' => 0,
                    'credit' => $booking['extra_fee'],
                    'balance' => $account->account_payable,
                    'from_user_id' => $provider_user_id,
                    'to_user_id' => $provider_user_id,
                    'from_user_account' => ACCOUNT_STATES[2]['value'],
                    'to_user_account' => null
                ]);
            }

            if ($admin_commission > 0) {
                //Admin transactions (for commission)
                $account = Account::where('user_id', $admin_user_id)->first();
                $account->account_receivable += $admin_commission;
                $account->save();

                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => 0,
                    'booking_repeat_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['receivable_commission'],
                    'debit' => 0,
                    'credit' => $admin_commission,
                    'balance' => $account->account_receivable,
                    'from_user_id' => $admin_user_id,
                    'to_user_id' => $provider_user_id,
                    'from_user_account' => ACCOUNT_STATES[3]['value'],
                    'to_user_account' => null
                ]);
            }

            if ($booking['extra_fee']) {
                //Admin transactions (for commission)
                $account = Account::where('user_id', $admin_user_id)->first();
                $account->account_receivable += $booking['extra_fee'];
                $account->save();

                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => 0,
                    'booking_repeat_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['received_extra_fee'],
                    'debit' => 0,
                    'credit' => $booking['extra_fee'],
                    'balance' => $account->account_receivable,
                    'from_user_id' => $provider_user_id,
                    'to_user_id' => $admin_user_id,
                    'from_user_account' => ACCOUNT_STATES[3]['value'],
                    'to_user_account' => null
                ]);
            }

            //expense (admin)
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->total_expense += $promotional_cost_by_admin;
            $account->save();

            //expense (provider)
            $account = Account::where('user_id', $provider_user_id)->first();
            $account->total_expense += $promotional_cost_by_provider;
            $account->save();
        });
    }
}

if (!function_exists('completeBookingTransactionForPartialCas')) {
    /**
     * //digital
     * Admin (-pending) [customer paid]
     * Admin (+received) [commission]
     * Admin (+payable) [provider earning]
     * Provider (+account_receivable) [provider earning]
     * // CAS
     * Provider (+received_balance) [provider earning]
     * Provider (+account_payable) [commission]
     * Provider (+account_receivable) [commission]
     *
     * @param $booking
     * @return void
     */
    // function completeBookingTransactionForPartialCas($booking): void
    // {
    //     // Get partial payment from parent if this is a child booking
    //     $partialPaymentBookingId = $booking->parent_booking_id ?? $booking->id;
    //     $booking_partial_payment = BookingPartialPayment::where('booking_id', $partialPaymentBookingId)
    //         ->where('paid_with', 'wallet')->first();

    //     if (!$booking_partial_payment) {
    //         return;
    //     }

    //     $wallet_paid = (float) $booking_partial_payment->paid_amount;
    //     $total_amount = (float) $booking['total_booking_amount'];
    //     $cash_due = $total_amount - $wallet_paid;

    //     // Get commission from ledger snapshot
    //     $ledgerSnapshot = getBookingLedgerSnapshot($booking);
    //     $admin_commission = (float) $ledgerSnapshot['admin_commission'];
    //     $provider_earning = (float) $ledgerSnapshot['provider_earning'];

    //     // If admin_commission is 0, fetch directly from service_commissions table
    //     if ($admin_commission == 0 && $booking->detail->isNotEmpty()) {
    //         foreach ($booking->detail as $detail) {
    //             $serviceCommission = \Modules\ServiceManagement\Entities\ServiceCommission::where('service_id', $detail->service_id)->first();
    //             if ($serviceCommission) {
    //                 $commission_value = (float) $serviceCommission->commission;
    //                 $commission_type = $serviceCommission->commission_type;

    //                 if ($commission_type == 'percent') {
    //                     $service_cost = (float) $detail->service_cost * (int) $detail->quantity;
    //                     $admin_commission = ($commission_value / 100) * $service_cost;
    //                 } else {
    //                     $admin_commission = $commission_value;
    //                 }
    //                 $provider_earning = $total_amount - $admin_commission;
    //                 break;
    //             }
    //         }
    //     }

    //     // SIMPLE FORMULAS:
    //     $account_receivable = max(0, $wallet_paid - $admin_commission);
    //     $account_payable = $admin_commission;

    //     // user ids
    //     $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
    //     $provider_user_id = get_user_id($booking['provider_id'], PROVIDER_USER_TYPES[0]);

    //     DB::transaction(function () use ($booking, $admin_user_id, $provider_user_id, $wallet_paid, $cash_due, $admin_commission, $provider_earning, $account_receivable, $account_payable) {

    //         // Get accounts
    //         $adminAccount = Account::where('user_id', $admin_user_id)->first();
    //         $providerAccount = Account::where('user_id', $provider_user_id)->first();

    //         // Log before update
    //         \Illuminate\Support\Facades\Log::info('Before update - admin account', [
    //             'booking_id' => $booking['id'],
    //             'current_account_payable' => $adminAccount->account_payable,
    //             'current_received_balance' => $adminAccount->received_balance,
    //             'current_balance_pending' => $adminAccount->balance_pending,
    //         ]);

    //         /** DIGITAL/WALLET PORTION */
    //         $adminAccount->balance_pending -= $wallet_paid;
    //         $adminAccount->received_balance += $admin_commission;
    //         $adminAccount->account_payable = ($adminAccount->account_payable ?? 0) + $account_payable; // Using assignment for clarity

    //         // Save admin account immediately
    //         $adminAccount->save();

    //         // Log after save to verify
    //         \Illuminate\Support\Facades\Log::info('After admin save - verification', [
    //             'booking_id' => $booking['id'],
    //             'saved_account_payable' => $adminAccount->account_payable,
    //             'saved_received_balance' => $adminAccount->received_balance,
    //         ]);

    //         // Admin transaction (-pending)
    //         $primary_transaction = Transaction::create([
    //             'ref_trx_id' => null,
    //             'booking_id' => $booking['id'],
    //             'trx_type' => TRX_TYPE['pending_amount'],
    //             'debit' => $wallet_paid,
    //             'credit' => 0,
    //             'balance' => $adminAccount->balance_pending,
    //             'from_user_id' => $admin_user_id,
    //             'to_user_id' => $provider_user_id,
    //             'from_user_account' => ACCOUNT_STATES[0]['value'],
    //             'to_user_account' => null
    //         ]);

    //         // Admin commission transaction
    //         if ($admin_commission > 0) {
    //             Transaction::create([
    //                 'ref_trx_id' => $primary_transaction['id'],
    //                 'booking_id' => $booking['id'],
    //                 'trx_type' => TRX_TYPE['received_commission'],
    //                 'debit' => 0,
    //                 'credit' => $admin_commission,
    //                 'balance' => $adminAccount->received_balance,
    //                 'from_user_id' => $admin_user_id,
    //                 'to_user_id' => $admin_user_id,
    //                 'from_user_account' => ACCOUNT_STATES[1]['value'],
    //                 'to_user_account' => null
    //             ]);
    //         }

    //         // Provider account_receivable and payable
    //         $providerAccount->account_receivable += $account_receivable;
    //         $providerAccount->account_payable = ($providerAccount->account_payable ?? 0) + $account_payable;

    //         Transaction::create([
    //             'ref_trx_id' => $primary_transaction['id'],
    //             'booking_id' => $booking['id'],
    //             'trx_type' => TRX_TYPE['receivable_amount'],
    //             'debit' => 0,
    //             'credit' => $account_receivable,
    //             'balance' => $providerAccount->account_receivable,
    //             'from_user_id' => $admin_user_id,
    //             'to_user_id' => $provider_user_id,
    //             'from_user_account' => null,
    //             'to_user_account' => ACCOUNT_STATES[3]['value']
    //         ]);

    //         // Admin payable transaction (commission owed by provider)
    //         Transaction::create([
    //             'ref_trx_id' => $primary_transaction['id'],
    //             'booking_id' => $booking['id'],
    //             'trx_type' => TRX_TYPE['payable_amount'],
    //             'debit' => 0,
    //             'credit' => $account_payable,
    //             'balance' => $adminAccount->account_payable,
    //             'from_user_id' => $admin_user_id,
    //             'to_user_id' => $admin_user_id,
    //             'from_user_account' => ACCOUNT_STATES[2]['value'],
    //             'to_user_account' => null
    //         ]);

    //         /** CASH PORTION */
    //         if ($cash_due > 0) {
    //             $providerAccount->received_balance += $provider_earning;

    //             Transaction::create([
    //                 'ref_trx_id' => $primary_transaction['id'],
    //                 'booking_id' => $booking['id'],
    //                 'trx_type' => TRX_TYPE['received_amount'],
    //                 'debit' => 0,
    //                 'credit' => $provider_earning,
    //                 'balance' => $providerAccount->received_balance,
    //                 'from_user_id' => $provider_user_id,
    //                 'to_user_id' => $provider_user_id,
    //                 'from_user_account' => null,
    //                 'to_user_account' => ACCOUNT_STATES[1]['value']
    //             ]);
    //         }

    //         $providerAccount->save();

    //         // Final verification from database
    //         $finalAdminAccount = Account::where('user_id', $admin_user_id)->first();

    //         \Illuminate\Support\Facades\Log::info('Partial CAS Transaction Completed', [
    //             'booking_id' => $booking['id'],
    //             'wallet_paid' => $wallet_paid,
    //             'cash_due' => $cash_due,
    //             'admin_commission' => $admin_commission,
    //             'account_receivable' => $account_receivable,
    //             'account_payable' => $account_payable,
    //             'admin_received_balance' => $adminAccount->received_balance,
    //             'admin_payable' => $adminAccount->account_payable,
    //             'FINAL_DB_account_payable' => $finalAdminAccount->account_payable,
    //             'provider_account_receivable' => $providerAccount->account_receivable,
    //             'provider_received_balance' => $providerAccount->received_balance,
    //         ]);
    //     });
    // }
    function completeBookingTransactionForPartialCas($booking): void
    {
        // Get partial payment from parent if this is a child booking
        $partialPaymentBookingId = $booking->parent_booking_id ?? $booking->id;
        $booking_partial_payment = BookingPartialPayment::where('booking_id', $partialPaymentBookingId)
            ->where('paid_with', 'wallet')->first();

        if (!$booking_partial_payment) {
            return;
        }

        $wallet_paid = (float) $booking_partial_payment->paid_amount;
        $total_amount = (float) $booking['total_booking_amount'];
        $cash_due = $total_amount - $wallet_paid;

        // Get commission from ledger snapshot
        $ledgerSnapshot = getBookingLedgerSnapshot($booking);
        $admin_commission = (float) $ledgerSnapshot['admin_commission'];
        $provider_earning = (float) $ledgerSnapshot['provider_earning'];

        // If admin_commission is 0, fetch directly from service_commissions table
        $admin_commission = 0;  // Initialize to 0
        if ($booking->detail->isNotEmpty()) {
            foreach ($booking->detail as $detail) {
                $serviceCommission = ServiceCommission::where('service_id', $detail->service_id)->first();
                if ($serviceCommission) {
                    $commission_value = (float) $serviceCommission->commission;
                    $commission_type = $serviceCommission->commission_type;
                    $item_commission = 0;

                    if ($commission_type == 'percent') {
                        $service_cost = (float) $detail->service_cost * (int) $detail->quantity;
                        $item_commission = ($commission_value / 100) * $service_cost;
                    } else {
                        $item_commission = $commission_value;
                    }

                    // FIX: Use += to accumulate commission from all variants
                    $admin_commission += $item_commission;
                }
            }
            // Calculate provider earning after accumulating all commissions
            $provider_earning = $total_amount - $admin_commission;
        }

        // SIMPLE FORMULAS:
        $account_receivable = max(0, $wallet_paid - $admin_commission);
        $account_payable = $admin_commission;

        // user ids
        $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
        $provider_user_id = get_user_id($booking['provider_id'], PROVIDER_USER_TYPES[0]);

        DB::transaction(function () use ($booking, $admin_user_id, $provider_user_id, $wallet_paid, $cash_due, $admin_commission, $provider_earning, $account_receivable, $account_payable) {

            // Get accounts
            $adminAccount = Account::where('user_id', $admin_user_id)->first();
            $providerAccount = Account::where('user_id', $provider_user_id)->first();

            // Log before update
            \Illuminate\Support\Facades\Log::info('Before update - admin account', [
                'booking_id' => $booking['id'],
                'current_account_payable' => $adminAccount->account_payable,
                'current_received_balance' => $adminAccount->received_balance,
                'current_balance_pending' => $adminAccount->balance_pending,
            ]);

            /** DIGITAL/WALLET PORTION */
            $adminAccount->balance_pending -= $wallet_paid;
            $adminAccount->received_balance += $admin_commission;
            $adminAccount->account_payable = ($adminAccount->account_payable ?? 0) + $account_payable; // Using assignment for clarity

            // Save admin account immediately
            $adminAccount->save();

            // Log after save to verify
            \Illuminate\Support\Facades\Log::info('After admin save - verification', [
                'booking_id' => $booking['id'],
                'saved_account_payable' => $adminAccount->account_payable,
                'saved_received_balance' => $adminAccount->received_balance,
            ]);

            // Admin transaction (-pending)
            $primary_transaction = Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => $booking['id'],
                'trx_type' => TRX_TYPE['pending_amount'],
                'debit' => $wallet_paid,
                'credit' => 0,
                'balance' => $adminAccount->balance_pending,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => ACCOUNT_STATES[0]['value'],
                'to_user_account' => null
            ]);

            // Admin commission transaction
            if ($admin_commission > 0) {
                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['received_commission'],
                    'debit' => 0,
                    'credit' => $admin_commission,
                    'balance' => $adminAccount->received_balance,
                    'from_user_id' => $admin_user_id,
                    'to_user_id' => $admin_user_id,
                    'from_user_account' => ACCOUNT_STATES[1]['value'],
                    'to_user_account' => null
                ]);
            }

            // Provider account_receivable and payable
            $providerAccount->account_receivable += $account_receivable;
            $providerAccount->account_payable = ($providerAccount->account_payable ?? 0) + $account_payable;

            Transaction::create([
                'ref_trx_id' => $primary_transaction['id'],
                'booking_id' => $booking['id'],
                'trx_type' => TRX_TYPE['receivable_amount'],
                'debit' => 0,
                'credit' => $account_receivable,
                'balance' => $providerAccount->account_receivable,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[3]['value']
            ]);

            // Admin payable transaction (commission owed by provider)
            Transaction::create([
                'ref_trx_id' => $primary_transaction['id'],
                'booking_id' => $booking['id'],
                'trx_type' => TRX_TYPE['payable_amount'],
                'debit' => 0,
                'credit' => $account_payable,
                'balance' => $adminAccount->account_payable,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $admin_user_id,
                'from_user_account' => ACCOUNT_STATES[2]['value'],
                'to_user_account' => null
            ]);

            /** CASH PORTION */
            if ($cash_due > 0) {
                $providerAccount->received_balance += $provider_earning;

                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['received_amount'],
                    'debit' => 0,
                    'credit' => $provider_earning,
                    'balance' => $providerAccount->received_balance,
                    'from_user_id' => $provider_user_id,
                    'to_user_id' => $provider_user_id,
                    'from_user_account' => null,
                    'to_user_account' => ACCOUNT_STATES[1]['value']
                ]);
            }

            $providerAccount->save();

            // Final verification from database
            $finalAdminAccount = Account::where('user_id', $admin_user_id)->first();

            \Illuminate\Support\Facades\Log::info('Partial CAS Transaction Completed', [
                'booking_id' => $booking['id'],
                'wallet_paid' => $wallet_paid,
                'cash_due' => $cash_due,
                'admin_commission' => $admin_commission,
                'account_receivable' => $account_receivable,
                'account_payable' => $account_payable,
                'admin_received_balance' => $adminAccount->received_balance,
                'admin_payable' => $adminAccount->account_payable,
                'FINAL_DB_account_payable' => $finalAdminAccount->account_payable,
                'provider_account_receivable' => $providerAccount->account_receivable,
                'provider_received_balance' => $providerAccount->received_balance,
            ]);
        });
    }
} //partially paid

if (!function_exists('completeBookingTransactionForPartialDigital')) {
    /**
     * @param $booking
     * @return void
     */
    function completeBookingTransactionForPartialDigital($booking): void
    {
        $ledgerSnapshot = getBookingLedgerSnapshot($booking);
        $admin_commission = $ledgerSnapshot['admin_commission'];
        $provider_earning = $ledgerSnapshot['provider_earning'];
        $promotional_cost_by_admin = $ledgerSnapshot['promotional_cost_by_admin'];
        $promotional_cost_by_provider = $ledgerSnapshot['promotional_cost_by_provider'];

        //user ids (from/to)
        $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
        $provider_user_id = get_user_id($booking['provider_id'], PROVIDER_USER_TYPES[0]);

        DB::transaction(function () use ($booking, $admin_user_id, $provider_user_id, $admin_commission, $provider_earning, $promotional_cost_by_admin, $promotional_cost_by_provider) {

            $account = Account::where('user_id', $admin_user_id)->first();
            $account->balance_pending -= ($booking['total_booking_amount'] - $booking['additional_charge']);
            $account->save();

            //Admin transaction (-pending)
            $primary_transaction = Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => $booking['id'],
                'trx_type' => TRX_TYPE['pending_amount'],
                'debit' => ($booking['total_booking_amount'] - $booking['additional_charge']),
                'credit' => 0,
                'balance' => $account->balance_pending,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => ACCOUNT_STATES[0]['value'],
                'to_user_account' => null
            ]);

            //Provider transactions (+receivable)
            $account = Account::where('user_id', $provider_user_id)->first();
            $account->account_receivable += $provider_earning;
            $account->save();

            Transaction::create([
                'ref_trx_id' => $primary_transaction['id'],
                'booking_id' => $booking['id'],
                'trx_type' => TRX_TYPE['receivable_amount'],
                'debit' => 0,
                'credit' => $provider_earning,
                'balance' => $account->account_receivable,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[3]['value']
            ]);

            if ($admin_commission > 0) {
                //Admin transactions for commission (+received)
                $account = Account::where('user_id', $admin_user_id)->first();
                $account->received_balance += $admin_commission;
                $account->save();

                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['received_commission'],
                    'debit' => 0,
                    'credit' => $admin_commission,
                    'balance' => $account->received_balance,
                    'from_user_id' => $admin_user_id,
                    'to_user_id' => $admin_user_id,
                    'from_user_account' => ACCOUNT_STATES[1]['value'],
                    'to_user_account' => null
                ]);
            }

            //Admin transactions (+payable)
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->account_payable += $provider_earning;
            $account->save();

            Transaction::create([
                'ref_trx_id' => $primary_transaction['id'],
                'booking_id' => $booking['id'],
                'trx_type' => TRX_TYPE['payable_amount'],
                'debit' => 0,
                'credit' => $provider_earning,
                'balance' => $account->account_payable,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $admin_user_id,
                'from_user_account' => ACCOUNT_STATES[2]['value'],
                'to_user_account' => null
            ]);

            //expense
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->total_expense += $promotional_cost_by_admin;
            $account->save();

            $account = Account::where('user_id', $provider_user_id)->first();
            $account->total_expense += $promotional_cost_by_provider;
            $account->save();

            // Normalize account net positions for both admin and provider.
            $adminAccount = Account::where('user_id', $admin_user_id)->first();
            normalizeAccountReceivablePayable($adminAccount);

            $providerAccount = Account::where('user_id', $provider_user_id)->first();
            normalizeAccountReceivablePayable($providerAccount);
        });
    }
} //partially paid

if (!function_exists('completeBookingTransactionForDigitalPaymentAndExtraService')) {
    function completeBookingTransactionForDigitalPaymentAndExtraService($booking): void
    {
        $ledgerSnapshot = getBookingLedgerSnapshot($booking);
        $admin_commission = $ledgerSnapshot['admin_commission'];
        $provider_earning = $ledgerSnapshot['provider_earning'];
        $promotional_cost_by_admin = $ledgerSnapshot['promotional_cost_by_admin'];
        $promotional_cost_by_provider = $ledgerSnapshot['promotional_cost_by_provider'];

        //user ids (from/to)
        $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
        $provider_user_id = get_user_id($booking['provider_id'], PROVIDER_USER_TYPES[0]);

        //----------------------------

        DB::transaction(function () use ($booking, $admin_user_id, $provider_user_id, $admin_commission, $provider_earning, $promotional_cost_by_admin, $promotional_cost_by_provider) {

            //=============== DIGITAL ===============
            $digitally_paid_booking_amount = $booking['total_booking_amount'] - $booking['additional_charge'];
            $commission_for_digital =  round(($admin_commission * $digitally_paid_booking_amount) / $booking['total_booking_amount'], 2);
            $provider_earning_for_digital = ($provider_earning * $digitally_paid_booking_amount) / $booking['total_booking_amount'];


            //Admin transaction (-pending)
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->balance_pending -= $digitally_paid_booking_amount;
            $account->save();

            $primary_transaction = Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => $booking['id'],
                'trx_type' => TRX_TYPE['pending_amount'],
                'debit' => $digitally_paid_booking_amount,
                'credit' => 0,
                'balance' => $account->balance_pending,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => ACCOUNT_STATES[0]['value'],
                'to_user_account' => null
            ]);

            //Provider transactions (+receivable)
            $account = Account::where('user_id', $provider_user_id)->first();
            $account->account_receivable += $provider_earning_for_digital;
            $account->save();

            Transaction::create([
                'ref_trx_id' => $primary_transaction['id'],
                'booking_id' => $booking['id'],
                'trx_type' => TRX_TYPE['receivable_amount'],
                'debit' => 0,
                'credit' => $provider_earning_for_digital,
                'balance' => $account->account_receivable,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[3]['value']
            ]);

            if ($admin_commission > 0) {
                //Admin transactions for commission (+received)
                $account = Account::where('user_id', $admin_user_id)->first();
                $account->received_balance += $commission_for_digital;
                $account->save();

                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['received_commission'],
                    'debit' => 0,
                    'credit' => $commission_for_digital,
                    'balance' => $account->received_balance,
                    'from_user_id' => $admin_user_id,
                    'to_user_id' => $admin_user_id,
                    'from_user_account' => ACCOUNT_STATES[1]['value'],
                    'to_user_account' => null
                ]);
            }

            //admin extra fee transaction
            if ($booking['extra_fee'] > 0) {
                //Admin transactions for extra fee (+received)
                $account = Account::where('user_id', $admin_user_id)->first();
                $account->received_balance += $booking['extra_fee'];
                $account->save();

                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['received_extra_fee'],
                    'debit' => 0,
                    'credit' => $booking['extra_fee'],
                    'balance' => $account->received_balance,
                    'from_user_id' => $admin_user_id,
                    'to_user_id' => $admin_user_id,
                    'from_user_account' => ACCOUNT_STATES[1]['value'],
                    'to_user_account' => null
                ]);
            }

            //Admin transactions (+payable)
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->account_payable += $provider_earning_for_digital;
            $account->save();

            Transaction::create([
                'ref_trx_id' => $primary_transaction['id'],
                'booking_id' => $booking['id'],
                'trx_type' => TRX_TYPE['payable_amount'],
                'debit' => 0,
                'credit' => $provider_earning_for_digital,
                'balance' => $account->account_payable,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $admin_user_id,
                'from_user_account' => ACCOUNT_STATES[2]['value'],
                'to_user_account' => null
            ]);

            //=============== CAS ===============
            $due_amount = 0;
            if ($booking?->booking_details_amounts->count() == 1) {
                $due_amount = $booking?->booking_details_amounts->where('paid_with', 'wallet')->first()?->due_amount ?? 0;
            }
            $due_booking_amount = $booking['additional_charge'] + $booking['removed_booking_amount'] + $due_amount;
            $commission_for_cas =  round(($admin_commission * $due_booking_amount) / $booking['total_booking_amount'], 2);
            $provider_earning_for_cas = ($provider_earning * $due_booking_amount) / $booking['total_booking_amount'];

            //Provider transactions
            $account = Account::where('user_id', $provider_user_id)->first();
            $account->received_balance += $provider_earning_for_cas;
            $account->save();

            $primary_transaction = Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => $booking['id'],
                'trx_type' => TRX_TYPE['received_amount'],
                'debit' => 0,
                'credit' => $provider_earning_for_cas,
                'balance' => $account->received_balance,
                'from_user_id' => $provider_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[1]['value']
            ]);

            if ($admin_commission > 0) {
                //Provider transactions (for commission)
                $account = Account::where('user_id', $provider_user_id)->first();
                $account->account_payable += $commission_for_cas;
                $account->save();

                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['payable_commission'],
                    'debit' => 0,
                    'credit' => $commission_for_cas,
                    'balance' => $account->account_payable,
                    'from_user_id' => $provider_user_id,
                    'to_user_id' => $provider_user_id,
                    'from_user_account' => ACCOUNT_STATES[2]['value'],
                    'to_user_account' => null
                ]);
            }

            if ($admin_commission > 0) {
                //Admin transactions (for commission)
                $account = Account::where('user_id', $admin_user_id)->first();
                $account->account_receivable += $commission_for_cas;
                $account->save();

                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['receivable_commission'],
                    'debit' => 0,
                    'credit' => $commission_for_cas,
                    'balance' => $account->account_receivable,
                    'from_user_id' => $admin_user_id,
                    'to_user_id' => $provider_user_id,
                    'from_user_account' => ACCOUNT_STATES[3]['value'],
                    'to_user_account' => null
                ]);
            }

            //expense
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->total_expense += $promotional_cost_by_admin;
            $account->save();

            $account = Account::where('user_id', $provider_user_id)->first();
            $account->total_expense += $promotional_cost_by_provider;
            $account->save();

            // Normalize account net positions for both admin and provider.
            $adminAccount = Account::where('user_id', $admin_user_id)->first();
            normalizeAccountReceivablePayable($adminAccount);

            $providerAccount = Account::where('user_id', $provider_user_id)->first();
            normalizeAccountReceivablePayable($providerAccount);
        });
    }
} //edited booking
if (!function_exists('completeBookingRepeatTransactionForDigitalPaymentAndExtraService')) {
    function completeBookingRepeatTransactionForDigitalPaymentAndExtraService($booking): void
    {
        $ledgerSnapshot = getBookingLedgerSnapshot($booking);
        $admin_commission = $ledgerSnapshot['admin_commission'];
        $provider_earning = $ledgerSnapshot['provider_earning'];
        $promotional_cost_by_admin = $ledgerSnapshot['promotional_cost_by_admin'];
        $promotional_cost_by_provider = $ledgerSnapshot['promotional_cost_by_provider'];

        //user ids (from/to)
        $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
        $provider_user_id = get_user_id($booking['provider_id'], PROVIDER_USER_TYPES[0]);

        //----------------------------

        DB::transaction(function () use ($booking, $admin_user_id, $provider_user_id, $admin_commission, $provider_earning, $promotional_cost_by_admin, $promotional_cost_by_provider) {

            //=============== DIGITAL ===============
            $digitally_paid_booking_amount = $booking['total_booking_amount'] - $booking['additional_charge'];
            $commission_for_digital =  round(($admin_commission * $digitally_paid_booking_amount) / $booking['total_booking_amount'], 2);
            $provider_earning_for_digital = ($provider_earning * $digitally_paid_booking_amount) / $booking['total_booking_amount'];


            //Admin transaction (-pending)
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->balance_pending -= $digitally_paid_booking_amount;
            $account->save();

            $primary_transaction = Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => 0,
                'booking_repeat_id' => $booking['id'],
                'trx_type' => TRX_TYPE['pending_amount'],
                'debit' => $digitally_paid_booking_amount,
                'credit' => 0,
                'balance' => $account->balance_pending,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => ACCOUNT_STATES[0]['value'],
                'to_user_account' => null
            ]);

            //Provider transactions (+receivable)
            $account = Account::where('user_id', $provider_user_id)->first();
            $account->account_receivable += $provider_earning_for_digital;
            $account->save();

            Transaction::create([
                'ref_trx_id' => $primary_transaction['id'],
                'booking_id' => 0,
                'booking_repeat_id' => $booking['id'],
                'trx_type' => TRX_TYPE['receivable_amount'],
                'debit' => 0,
                'credit' => $provider_earning_for_digital,
                'balance' => $account->account_receivable,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[3]['value']
            ]);

            if ($admin_commission > 0) {
                //Admin transactions for commission (+received)
                $account = Account::where('user_id', $admin_user_id)->first();
                $account->received_balance += $commission_for_digital;
                $account->save();

                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => 0,
                    'booking_repeat_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['received_commission'],
                    'debit' => 0,
                    'credit' => $commission_for_digital,
                    'balance' => $account->received_balance,
                    'from_user_id' => $admin_user_id,
                    'to_user_id' => $admin_user_id,
                    'from_user_account' => ACCOUNT_STATES[1]['value'],
                    'to_user_account' => null
                ]);
            }

            //admin extra fee transaction
            if ($booking['extra_fee'] > 0) {
                //Admin transactions for extra fee (+received)
                $account = Account::where('user_id', $admin_user_id)->first();
                $account->received_balance += $booking['extra_fee'];
                $account->save();

                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => 0,
                    'booking_repeat_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['received_extra_fee'],
                    'debit' => 0,
                    'credit' => $booking['extra_fee'],
                    'balance' => $account->received_balance,
                    'from_user_id' => $admin_user_id,
                    'to_user_id' => $admin_user_id,
                    'from_user_account' => ACCOUNT_STATES[1]['value'],
                    'to_user_account' => null
                ]);
            }

            //Admin transactions (+payable)
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->account_payable += $provider_earning_for_digital;
            $account->save();

            Transaction::create([
                'ref_trx_id' => $primary_transaction['id'],
                'booking_id' => 0,
                'booking_repeat_id' => $booking['id'],
                'trx_type' => TRX_TYPE['payable_amount'],
                'debit' => 0,
                'credit' => $provider_earning_for_digital,
                'balance' => $account->account_payable,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $admin_user_id,
                'from_user_account' => ACCOUNT_STATES[2]['value'],
                'to_user_account' => null
            ]);

            //=============== CAS ===============
            $due_amount = 0;
            if ($booking?->booking_details_amounts->count() == 1) {
                $due_amount = $booking?->booking_details_amounts->where('paid_with', 'wallet')->first()?->due_amount ?? 0;
            }
            $due_booking_amount = $booking['additional_charge'] + $booking['removed_booking_amount'] + $due_amount;
            $commission_for_cas =  round(($admin_commission * $due_booking_amount) / $booking['total_booking_amount'], 2);
            $provider_earning_for_cas = ($provider_earning * $due_booking_amount) / $booking['total_booking_amount'];

            //Provider transactions
            $account = Account::where('user_id', $provider_user_id)->first();
            $account->received_balance += $provider_earning_for_cas;
            $account->save();

            $primary_transaction = Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => 0,
                'booking_repeat_id' => $booking['id'],
                'trx_type' => TRX_TYPE['received_amount'],
                'debit' => 0,
                'credit' => $provider_earning_for_cas,
                'balance' => $account->received_balance,
                'from_user_id' => $provider_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[1]['value']
            ]);

            if ($admin_commission > 0) {
                //Provider transactions (for commission)
                $account = Account::where('user_id', $provider_user_id)->first();
                $account->account_payable += $commission_for_cas;
                $account->save();

                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => 0,
                    'booking_repeat_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['payable_commission'],
                    'debit' => 0,
                    'credit' => $commission_for_cas,
                    'balance' => $account->account_payable,
                    'from_user_id' => $provider_user_id,
                    'to_user_id' => $provider_user_id,
                    'from_user_account' => ACCOUNT_STATES[2]['value'],
                    'to_user_account' => null
                ]);
            }

            if ($admin_commission > 0) {
                //Admin transactions (for commission)
                $account = Account::where('user_id', $admin_user_id)->first();
                $account->account_receivable += $commission_for_cas;
                $account->save();

                Transaction::create([
                    'ref_trx_id' => $primary_transaction['id'],
                    'booking_id' => 0,
                    'booking_repeat_id' => $booking['id'],
                    'trx_type' => TRX_TYPE['receivable_commission'],
                    'debit' => 0,
                    'credit' => $commission_for_cas,
                    'balance' => $account->account_receivable,
                    'from_user_id' => $admin_user_id,
                    'to_user_id' => $provider_user_id,
                    'from_user_account' => ACCOUNT_STATES[3]['value'],
                    'to_user_account' => null
                ]);
            }

            //expense
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->total_expense += $promotional_cost_by_admin;
            $account->save();

            $account = Account::where('user_id', $provider_user_id)->first();
            $account->total_expense += $promotional_cost_by_provider;
            $account->save();

            // Normalize account net positions for both admin and provider.
            $adminAccount = Account::where('user_id', $admin_user_id)->first();
            normalizeAccountReceivablePayable($adminAccount);

            $providerAccount = Account::where('user_id', $provider_user_id)->first();
            normalizeAccountReceivablePayable($providerAccount);
        });
    }
} //edited booking repeat


//*** (admin) collect cash from provider ***
if (!function_exists('collectCashTransaction')) {
    function collectCashTransaction($provider_id, $collect_amount)
    {
        $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
        $provider_user_id = get_user_id($provider_id, PROVIDER_USER_TYPES[0]);

        DB::transaction(function () use ($collect_amount, $admin_user_id, $provider_user_id) {

            $account = Account::where('user_id', $provider_user_id)->first();
            $account->account_payable -= $collect_amount;
            $account->save();

            //Provider transactions
            $primary_transaction = Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => null,
                'trx_type' => TRX_TYPE['paid_commission'],
                'debit' => $collect_amount,
                'credit' => 0,
                'balance' => $account->account_payable,
                'from_user_id' => $provider_user_id,
                'to_user_id' => $admin_user_id,
                'from_user_account' => ACCOUNT_STATES[2]['value'],
                'to_user_account' => null
            ]);

            //Admin transactions
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->received_balance += $collect_amount;
            $account->save();

            Transaction::create([
                'ref_trx_id' => $primary_transaction['id'],
                'booking_id' => null,
                'trx_type' => TRX_TYPE['received_commission'],
                'debit' => 0,
                'credit' => $collect_amount,
                'balance' => $account->received_balance,
                'from_user_id' => $provider_user_id,
                'to_user_id' => $admin_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[1]['value']
            ]);

            //admin transactions
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->account_receivable -= $collect_amount;
            $account->save();

            Transaction::create([
                'ref_trx_id' => $primary_transaction['id'],
                'booking_id' => null,
                'trx_type' => TRX_TYPE['receivable_commission'],
                'debit' => $collect_amount,
                'credit' => 0,
                'balance' => $account->account_receivable,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => ACCOUNT_STATES[3]['value'],
                'to_user_account' => null
            ]);
        });
    }
}


//*** (provider) withdraw from admin ***
if (!function_exists('withdrawRequestTransaction')) {
    function withdrawRequestTransaction($provider_user_id, $withdrawal_amount)
    {

        DB::transaction(function () use ($withdrawal_amount, $provider_user_id) {

            //Provider transactions
            $account = Account::where('user_id', $provider_user_id)->first();
            $account->account_receivable -= $withdrawal_amount;
            $account->save();

            $primary_transaction = Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => null,
                'trx_type' => TRX_TYPE['withdrawable_amount'],
                'debit' => $withdrawal_amount,
                'credit' => 0,
                'balance' => $account->account_receivable,
                'from_user_id' => $provider_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => ACCOUNT_STATES[3]['value'],
                'to_user_account' => null
            ]);

            //Provider transactions
            $account = Account::where('user_id', $provider_user_id)->first();
            $account->balance_pending += $withdrawal_amount;
            $account->save();

            Transaction::create([
                'ref_trx_id' => $primary_transaction['id'],
                'booking_id' => null,
                'trx_type' => TRX_TYPE['pending_amount'],
                'debit' => 0,
                'credit' => $withdrawal_amount,
                'balance' => $account->balance_pending,
                'from_user_id' => $provider_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[0]['value']
            ]);
        });
    }
}

if (!function_exists('withdrawRequestAcceptTransaction')) {
    function withdrawRequestAcceptTransaction($provider_user_id, $withdrawal_amount)
    {
        $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;

        DB::transaction(function () use ($admin_user_id, $withdrawal_amount, $provider_user_id) {

            //Provider transactions
            $account = Account::where('user_id', $provider_user_id)->first();
            $account->balance_pending -= $withdrawal_amount;
            $account->save();

            $primary_transaction = Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => null,
                'trx_type' => TRX_TYPE['pending_amount'],
                'debit' => $withdrawal_amount,
                'credit' => 0,
                'balance' => $account->balance_pending,
                'from_user_id' => $provider_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => ACCOUNT_STATES[0]['value'],
                'to_user_account' => null
            ]);

            //Provider transactions
            $account = Account::where('user_id', $provider_user_id)->first();
            $account->total_withdrawn += $withdrawal_amount;
            $account->save();

            Transaction::create([
                'ref_trx_id' => $primary_transaction['id'],
                'booking_id' => null,
                'trx_type' => TRX_TYPE['received_amount'],
                'debit' => 0,
                'credit' => $withdrawal_amount,
                'balance' => $account->total_withdrawn,
                'from_user_id' => $provider_user_id,
                'to_user_id' => $admin_user_id,
                'from_user_account' => ACCOUNT_STATES[4]['value'],
                'to_user_account' => null
            ]);

            //Admin transactions
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->account_payable -= $withdrawal_amount;
            $account->save();

            Transaction::create([
                'ref_trx_id' => $primary_transaction['id'],
                'booking_id' => null,
                'trx_type' => TRX_TYPE['paid_amount'],
                'debit' => $withdrawal_amount,
                'credit' => 0,
                'balance' => $account->account_payable,
                'from_user_id' => $provider_user_id,
                'to_user_id' => $admin_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[2]['value']
            ]);
        });
    }
}

if (!function_exists('withdrawRequestAcceptForAdjustTransaction')) {
    function withdrawRequestAcceptForAdjustTransaction($provider_user_id, $withdrawal_amount)
    {
        $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;

        DB::transaction(function () use ($admin_user_id, $withdrawal_amount, $provider_user_id) {

            //Provider transactions
            $account = Account::where('user_id', $provider_user_id)->first();
            $account->account_receivable -= $withdrawal_amount;
            $account->save();

            $primary_transaction = Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => null,
                'trx_type' => TRX_TYPE['withdrawable_amount'],
                'debit' => $withdrawal_amount,
                'credit' => 0,
                'balance' => $account->account_receivable,
                'from_user_id' => $provider_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => ACCOUNT_STATES[3]['value'],
                'to_user_account' => null
            ]);

            //Provider transactions
            $account = Account::where('user_id', $provider_user_id)->first();
            $account->total_withdrawn += $withdrawal_amount;
            $account->save();

            $primary_transaction = Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => null,
                'trx_type' => TRX_TYPE['received_amount'],
                'debit' => 0,
                'credit' => $withdrawal_amount,
                'balance' => $account->total_withdrawn,
                'from_user_id' => $provider_user_id,
                'to_user_id' => $admin_user_id,
                'from_user_account' => ACCOUNT_STATES[4]['value'],
                'to_user_account' => null
            ]);

            //Admin transactions
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->account_payable -= $withdrawal_amount;
            $account->save();

            Transaction::create([
                'ref_trx_id' => $primary_transaction['id'],
                'booking_id' => null,
                'trx_type' => TRX_TYPE['paid_amount'],
                'debit' => $withdrawal_amount,
                'credit' => 0,
                'balance' => $account->account_payable,
                'from_user_id' => $provider_user_id,
                'to_user_id' => $admin_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[2]['value']
            ]);
        });
    }
}

if (!function_exists('withdrawRequestDenyTransaction')) {
    function withdrawRequestDenyTransaction($provider_user_id, $withdrawal_amount)
    {

        DB::transaction(function () use ($withdrawal_amount, $provider_user_id) {

            //Provider transactions
            $account = Account::where('user_id', $provider_user_id)->first();
            $account->account_receivable += $withdrawal_amount;
            $account->save();

            $primary_transaction = Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => null,
                'trx_type' => TRX_TYPE['withdrawable_amount'],
                'debit' => 0,
                'credit' => $withdrawal_amount,
                'balance' => $account->account_receivable,
                'from_user_id' => $provider_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => ACCOUNT_STATES[3]['value'],
                'to_user_account' => null
            ]);

            //Provider transactions
            $account = Account::where('user_id', $provider_user_id)->first();
            $account->balance_pending -= $withdrawal_amount;
            $account->save();

            Transaction::create([
                'ref_trx_id' => $primary_transaction['id'],
                'booking_id' => null,
                'trx_type' => TRX_TYPE['pending_amount'],
                'debit' => $withdrawal_amount,
                'credit' => 0,
                'balance' => $account->balance_pending,
                'from_user_id' => $provider_user_id,
                'to_user_id' => $provider_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[0]['value']
            ]);
        });
    }
}


//*** FUND ***
if (!function_exists('addFundTransaction')) {
    function addFundTransaction($user_id, $amount, $reference)
    {

        DB::transaction(function () use ($user_id, $amount, $reference) {

            //Provider transactions
            $user = User::where('id', $user_id)->first();
            $user->wallet_balance += $amount;
            $user->save();

            Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => null,
                'trx_type' => TRX_TYPE['fund_by_admin'],
                'debit' => 0,
                'credit' => $amount,
                'balance' => $user->wallet_balance,
                'from_user_id' => $user_id,
                'to_user_id' => $user_id,
                'from_user_account' => null,
                'to_user_account' => 'user_wallet',
                'reference_note' => $reference,
            ]);
        });
    }
}

//*** Referral Earn ***
if (!function_exists('referralEarningTransactionDuringRegistration')) {
    function referralEarningTransactionDuringRegistration($user, $amount)
    {

        DB::transaction(function () use ($user, $amount) {

            //Customer account
            $account = Account::where('user_id', $user->id)->first();
            $account->balance_pending += $amount;
            $account->save();

            Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => null,
                'trx_type' => TRX_TYPE['referral_earning'],
                'debit' => 0,
                'credit' => $amount,
                'balance' => $account->balance_pending,
                'from_user_id' => $user->id,
                'to_user_id' => $user->id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[0]['value'],
                'reference_note' => $user->ref_code,
            ]);
        });
    }
}

if (!function_exists('referralEarningTransactionAfterBookingComplete')) {
    function referralEarningTransactionAfterBookingComplete($user, $amount)
    {

        DB::transaction(function () use ($user, $amount) {

            //Customer account (removed from PENDING)
            $account = Account::where('user_id', $user->id)->first();
            $account->balance_pending -= $amount;
            $account->save();

            Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => null,
                'trx_type' => TRX_TYPE['referral_earning'],
                'debit' => $amount,
                'credit' => 0,
                'balance' => $account->balance_pending,
                'from_user_id' => $user->id,
                'to_user_id' => $user->id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[0]['value'],
                'reference_note' => $user->ref_code,
            ]);

            //Customer account (add in RECEIVABLE)
            $user = User::where('id', $user->id)->first();
            $user->wallet_balance += $amount;
            $user->save();

            Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => null,
                'trx_type' => TRX_TYPE['referral_earning'],
                'debit' => 0,
                'credit' => $amount,
                'balance' => $user->wallet_balance,
                'from_user_id' => $user->id,
                'to_user_id' => $user->id,
                'from_user_account' => null,
                'to_user_account' => 'user_wallet',
                'reference_note' => $user->ref_code,
            ]);
        });
    }
}

if (!function_exists('referralEarningTransactionAfterBookingCompleteFirst')) {
    function referralEarningTransactionAfterBookingCompleteFirst($user, $amount, $bookingId)
    {

        DB::transaction(function () use ($user, $amount, $bookingId) {

            $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;

            Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => $bookingId,
                'trx_type' => TRX_TYPE['referral_discount'],
                'debit' => $amount,
                'credit' => 0,
                'balance' => 0,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $admin_user_id,
                'from_user_account' => null,
                'to_user_account' => null,
                'reference_note' => $user->ref_code,
            ]);
        });
    }
}

if (!function_exists('referralEarningTransactionAfterBookingRepeatCompleteFirst')) {
    function referralEarningTransactionAfterBookingRepeatCompleteFirst($user, $amount, $bookingId)
    {

        DB::transaction(function () use ($user, $amount, $bookingId) {

            $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;

            Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => 0,
                'booking_repeat_id' => $bookingId,
                'trx_type' => TRX_TYPE['referral_discount'],
                'debit' => $amount,
                'credit' => 0,
                'balance' => 0,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $admin_user_id,
                'from_user_account' => null,
                'to_user_account' => null,
                'reference_note' => $user->ref_code,
            ]);
        });
    }
}


//*** Loyalty point ***
if (!function_exists('loyaltyPointWalletTransferTransaction')) {
    function loyaltyPointWalletTransferTransaction($user_id, $point, $amount)
    {

        DB::transaction(function () use ($user_id, $point, $amount) {

            //Customer (loyalty_point update)
            $user = User::find($user_id);
            $user->loyalty_point -= $point;
            $user->wallet_balance += $amount;
            $user->save();

            //transaction
            Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => null,
                'trx_type' => TRX_TYPE['loyalty_point_earning'],
                'debit' => 0,
                'credit' => $amount,
                'balance' => $amount,
                'from_user_id' => $user_id,
                'to_user_id' => $user_id,
                'from_user_account' => null,
                'to_user_account' => 'user_wallet',
                'reference_note' => null,
            ]);

            //transaction
            LoyaltyPointTransaction::create([
                'user_id' => $user_id,
                'debit' => $point,
                'credit' => 0,
                'balance' => $user->loyalty_point,
                'reference' => null,
                'transaction_type' => null,
            ]);
        });
    }
}

if (!function_exists('loyaltyPointTransaction')) {
    function loyaltyPointTransaction($user_id, $point, $reference = null, $transactionType = 'booking')
    {

        return DB::transaction(function () use ($user_id, $point, $reference, $transactionType) {
            if (
                $reference && LoyaltyPointTransaction::where('user_id', $user_id)
                ->where('reference', (string)$reference)
                ->where('transaction_type', $transactionType)
                ->exists()
            ) {
                return false;
            }

            //point update
            $user = User::find($user_id);
            $user->loyalty_point += $point;
            $user->save();

            //transaction
            LoyaltyPointTransaction::create([
                'user_id' => $user_id,
                'debit' => 0,
                'credit' => $point,
                'balance' => $user->loyalty_point,
                'reference' => $reference ? (string)$reference : null,
                'transaction_type' => $transactionType,
            ]);

            return true;
        });
    }
}

//*** Add Fund ***
if (!function_exists('addFundTransactions')) {
    function addFundTransactions($customer_user_id, $amount): void
    {
        $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
        $bonus = get_add_money_bonus($amount);

        DB::transaction(function () use ($customer_user_id, $amount, $admin_user_id, $bonus) {

            //customer wallet update
            $user = User::find($customer_user_id);
            $user->wallet_balance += $amount;
            $user->save();

            Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => null,
                'trx_type' => TRX_TYPE['add_fund'],
                'debit' => 0,
                'credit' => $amount,
                'balance' => $user->wallet_balance,
                'from_user_id' => null,
                'to_user_id' => $user->id,
                'from_user_account' => null,
                'to_user_account' => 'user_wallet',
                'reference_note' => null,
            ]);

            if ($bonus > 0) {
                $user = User::find($customer_user_id);
                $user->wallet_balance += $bonus;
                $user->save();

                //send notification
                $user = User::find($customer_user_id);
                $title =  with_currency_symbol($bonus) . ' ' . get_push_notification_message('add_fund_wallet_bonus', 'customer_notification', $user?->current_language_key);
                $permission = isNotificationActive($user?->provider?->id, 'wallet', 'notification', 'user');
                $data_info = [
                    'user_name' => $user?->first_name . ' ' . $user->last_name
                ];
                if ($user->fcm_token && $title && $permission) {
                    device_notification($user->fcm_token, $title, null, null, null, NOTIFICATION_TYPE['wallet'], null, $customer_user_id, $data_info);
                }

                Transaction::create([
                    'ref_trx_id' => null,
                    'booking_id' => null,
                    'trx_type' => TRX_TYPE['add_fund_bonus'],
                    'debit' => 0,
                    'credit' => $bonus,
                    'balance' => $user->wallet_balance,
                    'from_user_id' => $admin_user_id,
                    'to_user_id' => $user->id,
                    'from_user_account' => null,
                    'to_user_account' => 'user_wallet',
                    'reference_note' => null,
                ]);

                //expense
                $account = Account::where('user_id', $admin_user_id)->first();
                $account->total_expense += $bonus;
                $account->save();
            }
        });
    }
}


//*** Refund ***
if (!function_exists('refundTransactionForCanceledBooking')) {
    /**
     * @param $booking
     * @return void
     */

    // in which single refund also working

    function refundTransactionForCanceledBooking($booking): void
    {
        // Prevent duplicate refunds - check if refund transaction already exists for this booking
        $refundAlreadyProcessed = Transaction::where('booking_id', $booking['id'])
            ->where('trx_type', TRX_TYPE['booking_refund'])
            ->exists();

        if ($refundAlreadyProcessed) {
            return;
        }

        $refund_amount = 0;
        $isChildBooking = !is_null($booking->parent_booking_id);

        if ($isChildBooking) {
            // Single service (child booking) cancelled - calculate proportional refund for this service only
            $parentBooking = $booking->parentBooking;

            // Get total paid amount for parent booking
            $totalPaidAmount = 0;
            if ($parentBooking->booking_partial_payments->isEmpty()) {
                if ($parentBooking->payment_method == 'offline_payment' && $parentBooking->is_paid) {
                    $totalPaidAmount = $parentBooking['total_booking_amount'];
                } elseif ($parentBooking->payment_method != 'offline_payment' && $parentBooking->payment_method != 'cash_after_service') {
                    $totalPaidAmount = $parentBooking['total_booking_amount'];
                }
            } else {
                if ($parentBooking->payment_method == 'offline_payment' && $parentBooking->is_paid) {
                    $totalPaidAmount = $parentBooking->booking_partial_payments->sum('paid_amount');
                } elseif ($parentBooking->payment_method == 'offline_payment' && !$parentBooking->is_paid) {
                    $totalPaidAmount = $parentBooking->booking_partial_payments->where('paid_with', '!=', 'offline_payment')->sum('paid_amount');
                } elseif ($parentBooking->payment_method != 'offline_payment') {
                    $totalPaidAmount = $parentBooking->booking_partial_payments->where('paid_with', '!=', 'cash_after_service')->sum('paid_amount');
                }
            }

            // Calculate proportional refund based on child booking amount
            $parentTotalAmount = $parentBooking['total_booking_amount'];
            if ($parentTotalAmount > 0 && $totalPaidAmount > 0) {
                $refund_amount = ($booking['total_booking_amount'] / $parentTotalAmount) * $totalPaidAmount;
                $refund_amount = round($refund_amount, 2);
            }
        } else {
            // Parent booking cancelled - calculate refund amount for remaining unpaid/refundable service value
            $totalPaidAmount = 0;
            if ($booking->booking_partial_payments->isEmpty()) {
                if ($booking->payment_method == 'offline_payment' && $booking->is_paid) {
                    $totalPaidAmount = $booking['total_booking_amount'];
                } elseif ($booking->payment_method != 'offline_payment' && $booking->payment_method != 'cash_after_service') {
                    $totalPaidAmount = $booking['total_booking_amount'];
                }
            } else {
                if ($booking->payment_method == 'offline_payment' && $booking->is_paid) {
                    $totalPaidAmount = $booking->booking_partial_payments->sum('paid_amount');
                } elseif ($booking->payment_method == 'offline_payment' && !$booking->is_paid) {
                    $totalPaidAmount = $booking->booking_partial_payments->where('paid_with', '!=', 'offline_payment')->sum('paid_amount');
                } elseif ($booking->payment_method != 'offline_payment') {
                    $totalPaidAmount = $booking->booking_partial_payments->where('paid_with', '!=', 'cash_after_service')->sum('paid_amount');
                }
            }

            $refundBookingIds = array_merge([$booking['id']], $booking->childBookings()->pluck('id')->toArray());
            $alreadyRefunded = Transaction::whereIn('booking_id', $refundBookingIds)
                ->where('trx_type', TRX_TYPE['booking_refund'])
                ->sum('credit');

            if ($totalPaidAmount > 0) {
                $refund_amount = max(0, round($totalPaidAmount - $alreadyRefunded, 2));
            }
        }

        if ($refund_amount == 0) return;

        // Process the refund
        $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;

        DB::transaction(function () use ($booking, $admin_user_id, $refund_amount) {
            // Admin transaction
            $account = Account::where('user_id', $admin_user_id)->first();
            if ($account->balance_pending >= $refund_amount) {
                $account->balance_pending -= $refund_amount;
            }
            $account->save();

            $primary_transaction = Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => $booking['id'],
                'trx_type' => TRX_TYPE['booking_refund'],
                'debit' => $refund_amount,
                'credit' => 0,
                'balance' => $account->balance_pending,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $admin_user_id,
                'from_user_account' => ACCOUNT_STATES[0]['value'],
                'to_user_account' => null
            ]);

            // Customer wallet transaction
            $user = User::find($booking['customer_id']);
            $user->wallet_balance += $refund_amount;
            $user->save();

            Transaction::create([
                'ref_trx_id' => $primary_transaction->id,
                'booking_id' => $booking['id'],
                'trx_type' => TRX_TYPE['booking_refund'],
                'debit' => 0,
                'credit' => $refund_amount,
                'balance' => $user->wallet_balance,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $booking->customer_id,
                'from_user_account' => null,
                'to_user_account' => 'user_wallet'
            ]);

            // Send notification
            $title = get_push_notification_message('refund', 'customer_notification', $booking?->customer?->current_language_key);
            if ($title && $booking?->customer?->fcm_token) {
                $message = with_currency_symbol($refund_amount) . ' ' . $title;
                if ($booking->parent_booking_id) {
                    $message = "Refund for cancelled service: " . $message;
                }
                device_notification($booking?->customer?->fcm_token, $message, null, null, $booking->id, 'booking');
            }
        });
    }

    // function refundTransactionForCanceledBooking($booking): void
    // {
    //     // Customer full-cancel flow can mark related child bookings as canceled too.
    //     // Refund must be processed only for the parent booking to avoid double credits.
    //     if (!is_null($booking->parent_booking_id)) {
    //         return;
    //     }

    //     $refund_amount = 0;
    //     if ($booking->booking_partial_payments->isEmpty()) {
    //         //not partial
    //         if ($booking->payment_method == 'offline_payment' && $booking->is_paid) {
    //             $refund_amount = $booking['total_booking_amount'];
    //         } elseif ($booking->payment_method != 'offline_payment' && $booking->payment_method != 'cash_after_service') {
    //             $refund_amount = $booking['total_booking_amount'];
    //         }
    //     } else {
    //         //partial
    //         if ($booking->payment_method == 'offline_payment' && $booking->is_paid) {
    //             $refund_amount = $booking->booking_partial_payments->sum('paid_amount');

    //         } elseif ($booking->payment_method == 'offline_payment' && !$booking->is_paid) {
    //             $refund_amount = $booking->booking_partial_payments->where('paid_with', '!=', 'offline_payment')->sum('paid_amount');

    //         } elseif ($booking->payment_method != 'offline_payment') {
    //             $refund_amount = $booking->booking_partial_payments->where('paid_with', '!=', 'cash_after_service')->sum('paid_amount');
    //         }
    //     }

    //     if ($refund_amount == 0) return;

    //     $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
    //     DB::transaction(function () use ($booking, $admin_user_id, $refund_amount) {
    //         $refundAlreadyProcessed = Transaction::where('booking_id', $booking['id'])
    //             ->where('trx_type', TRX_TYPE['booking_refund'])
    //             ->where('to_user_id', $booking->customer_id)
    //             ->where('to_user_account', 'user_wallet')
    //             ->exists();

    //         if ($refundAlreadyProcessed) {
    //             return;
    //         }

    //         //Admin transaction
    //         $account = Account::where('user_id', $admin_user_id)->first();
    //         if ($account->balance_pending >= $refund_amount) {
    //             $account->balance_pending -= $refund_amount;
    //         }
    //         $account->save();

    //         $primary_transaction = Transaction::create([
    //             'ref_trx_id' => null,
    //             'booking_id' => $booking['id'],
    //             'trx_type' => TRX_TYPE['booking_refund'],
    //             'debit' => $refund_amount,
    //             'credit' => 0,
    //             'balance' => $account->balance_pending,
    //             'from_user_id' => $admin_user_id,
    //             'to_user_id' => $admin_user_id,
    //             'from_user_account' => ACCOUNT_STATES[0]['value'],
    //             'to_user_account' => null
    //         ]);

    //         //customer transaction (wallet)
    //         $user = User::find($booking['customer_id']);
    //         $user->wallet_balance += $refund_amount;
    //         $user->save();

    //         Transaction::create([
    //             'ref_trx_id' => $primary_transaction->id,
    //             'booking_id' => $booking['id'],
    //             'trx_type' => TRX_TYPE['booking_refund'],
    //             'debit' => 0,
    //             'credit' => $refund_amount,
    //             'balance' => $user->wallet_balance,
    //             'from_user_id' => $admin_user_id,
    //             'to_user_id' => $booking->customer_id,
    //             'from_user_account' => null,
    //             'to_user_account' => 'user_wallet'
    //         ]);
    //         $title =  get_push_notification_message('refund', 'customer_notification', $booking?->customer?->current_language_key);
    //         if($title && $booking?->customer?->fcm_token){
    //             device_notification($booking?->customer?->fcm_token, with_currency_symbol($refund_amount) . ' ' . $title, null, null, $booking->id, 'booking');
    //         }
    //     });
    // }
}

if (!function_exists('purchaseSubscriptionTransaction')) {
    function purchaseSubscriptionTransaction($amount, $provider_id, $vat)
    {
        $totalAmount = $amount;
        $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
        $providerUserId = Provider::where('id', $provider_id)->value('user_id');

        $transactionId = null;

        DB::transaction(function () use ($providerUserId, $totalAmount, $vat, $admin_user_id, &$transactionId) {
            // Admin account update
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->received_balance += $totalAmount;
            $account->save();

            $transaction = Transaction::create([
                'ref_trx_id' => null,
                'trx_type' => TRX_TYPE['subscription_purchase'],
                'debit' => 0,
                'credit' => $totalAmount,
                'balance' => $account->received_balance,
                'from_user_id' => $providerUserId,
                'to_user_id' => $admin_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[1]['value']
            ]);

            $transactionId = $transaction->id;
        });

        return $transactionId;
    }
}

if (!function_exists('renewSubscriptionTransaction')) {
    function renewSubscriptionTransaction($amount, $provider_id, $vat)
    {
        $totalAmount = $amount;
        $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
        $providerUserId = Provider::where('id', $provider_id)->value('user_id');

        // Initialize a variable to store the transaction ID
        $transactionId = null;

        DB::transaction(function () use ($providerUserId, $totalAmount, $vat, $admin_user_id, &$transactionId) {
            // Admin account update
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->received_balance += $totalAmount;
            $account->save();

            // Admin transaction
            $transaction = Transaction::create([
                'ref_trx_id' => null,
                'trx_type' => TRX_TYPE['subscription_renew'],
                'debit' => 0,
                'credit' => $totalAmount,
                'balance' => $account->received_balance,
                'from_user_id' => $providerUserId,
                'to_user_id' => $admin_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[1]['value']
            ]);

            // Capture the transaction ID
            $transactionId = $transaction->id;
        });

        // Return the transaction ID
        return $transactionId;
    }
}

if (!function_exists('shiftSubscriptionTransaction')) {
    function shiftSubscriptionTransaction($amount, $provider_id, $vat)
    {
        $totalAmount = $amount;
        $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
        $providerUserId = Provider::where('id', $provider_id)->value('user_id');

        // Initialize a variable to store the transaction ID
        $transactionId = null;

        DB::transaction(function () use ($providerUserId, $totalAmount, $vat, $admin_user_id, &$transactionId) {
            // Admin account update
            $account = Account::where('user_id', $admin_user_id)->first();
            $account->received_balance += $totalAmount;
            $account->save();

            // Admin transaction
            $transaction = Transaction::create([
                'ref_trx_id' => null,
                'trx_type' => TRX_TYPE['subscription_shift'],
                'debit' => 0,
                'credit' => $totalAmount,
                'balance' => $account->received_balance,
                'from_user_id' => $providerUserId,
                'to_user_id' => $admin_user_id,
                'from_user_account' => null,
                'to_user_account' => ACCOUNT_STATES[1]['value']
            ]);

            // Capture the transaction ID
            $transactionId = $transaction->id;
        });

        // Return the transaction ID
        return $transactionId;
    }
}


if (!function_exists('shiftRefundSubscriptionTransaction')) {
    function shiftRefundSubscriptionTransaction($provider_id): void
    {
        $admin_user_id = User::where('user_type', ADMIN_USER_TYPES[0])->first()->id;
        $providerUserId = Provider::where('id', $provider_id)->value('user_id');

        $packageSubscriber = PackageSubscriber::where('provider_id', $provider_id)->first();
        $packagePrice = $packageSubscriber->package_price - $packageSubscriber->vat_amount;

        $today = Carbon::now();
        $startDate = Carbon::parse($packageSubscriber->package_start_date);
        $endDate = Carbon::parse($packageSubscriber->package_end_date);
        $packageTotalDays = $startDate->diffInDays($endDate, false) + 1;
        $availableDays = $today->diffInDays($endDate, false) + 1;
        $unitCost = $packagePrice / $packageTotalDays;
        $refundAmount = $availableDays * $unitCost;

        DB::transaction(function () use ($providerUserId, $provider_id, $admin_user_id, $refundAmount) {
            //Admin transaction
            $account = Account::where('user_id', $admin_user_id)->first();
            if ($account->balance_pending >= $refundAmount) {
                $account->received_balance -= $refundAmount;
            }
            $account->save();

            $primary_transaction = Transaction::create([
                'ref_trx_id' => null,
                'booking_id' => null,
                'trx_type' => TRX_TYPE['subscription_refund'],
                'debit' => $refundAmount,
                'credit' => 0,
                'balance' => $account->received_balance,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $admin_user_id,
                'from_user_account' => ACCOUNT_STATES[1]['value'],
                'to_user_account' => null
            ]);

            //provider transaction (receivable)
            $user = Account::where('user_id', $providerUserId)->first();
            $user->account_receivable += $refundAmount;
            $user->save();

            Transaction::create([
                'ref_trx_id' => $primary_transaction->id,
                'booking_id' => null,
                'trx_type' => TRX_TYPE['subscription_refund'],
                'debit' => 0,
                'credit' => $refundAmount,
                'balance' => $user->account_receivable,
                'from_user_id' => $admin_user_id,
                'to_user_id' => $providerUserId,
                'from_user_account' => null,
                'to_user_account' => 'account receivable'
            ]);
        });
    }
}
