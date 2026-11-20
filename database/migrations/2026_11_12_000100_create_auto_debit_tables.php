<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant auto-debit (rent charged to a saved card on the due date).
 *
 *  - auto_debit_mandates : the saved card (Paystack reusable authorization — never the card number).
 *  - auto_debit_setups   : a ₦50 card-verification checkout used to save a card without paying an invoice.
 *  - auto_debit_attempts : one row per charge attempt on an invoice; (invoiceId, attemptNo) is unique so a
 *                          double-fired job can never charge the same attempt twice.
 *  - payments.saveCard   : the tenant ticked "save this card" on a normal checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_debit_mandates', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('tenantId')->index();
            $table->string('userId')->nullable();
            $table->string('email');                       // Paystack requires the customer email on every charge
            $table->text('authorizationCode');             // encrypted via the model cast
            $table->string('cardBrand')->nullable();
            $table->string('last4', 4)->nullable();
            $table->string('expMonth', 2)->nullable();
            $table->string('expYear', 4)->nullable();
            $table->string('bank')->nullable();
            $table->string('signature')->nullable();       // Paystack card fingerprint — lets us spot duplicates
            $table->string('status')->default('active');   // active | cancelled | expired
            $table->string('enrolledAt');                  // ISO timestamp; only invoices due on/after this day are charged
            $table->string('cancelledAt')->nullable();
            $table->string('cancelReason')->nullable();
        });

        Schema::create('auto_debit_setups', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('reference')->unique();
            $table->string('tenantId')->index();
            $table->string('userId')->nullable();
            $table->integer('amount');                     // naira
            $table->string('status')->default('initiated'); // initiated | verified | failed
            $table->string('createdAt');
        });

        Schema::create('auto_debit_attempts', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('invoiceId');
            $table->string('tenantId')->index();
            $table->string('mandateId')->nullable();
            $table->string('paymentId')->nullable();
            $table->unsignedSmallInteger('attemptNo');
            $table->string('status');                      // pending | success | failed
            $table->string('reason')->nullable();
            $table->integer('amount');                     // naira charged / attempted
            $table->string('attemptedAt');
            $table->string('nextAttemptOn')->nullable();   // date of the scheduled retry, null when exhausted/succeeded
            $table->unique(['invoiceId', 'attemptNo']);
            $table->index('invoiceId');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('saveCard')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('saveCard'));
        Schema::dropIfExists('auto_debit_attempts');
        Schema::dropIfExists('auto_debit_setups');
        Schema::dropIfExists('auto_debit_mandates');
    }
};
