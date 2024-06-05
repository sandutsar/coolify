<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Subscription extends Model
{
    protected $guarded = [];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
    public function type()
    {
        if (isLemon()) {
            $basic = explode(',', config('subscription.lemon_squeezy_basic_plan_ids'));
            $pro = explode(',', config('subscription.lemon_squeezy_pro_plan_ids'));
            $ultimate = explode(',', config('subscription.lemon_squeezy_ultimate_plan_ids'));

            $subscription = $this->lemon_variant_id;
            if (in_array($subscription, $basic)) {
                return 'basic';
            }
            if (in_array($subscription, $pro)) {
                return 'pro';
            }
            if (in_array($subscription, $ultimate)) {
                return 'ultimate';
            }
        } else if (isStripe()) {
            if (!$this->stripe_plan_id) {
                return 'zero';
            }
            $subscription = Subscription::where('id', $this->id)->first();
            if (!$subscription) {
                return null;
            }
            $subscriptionPlanId = data_get($subscription, 'stripe_plan_id');
            if (!$subscriptionPlanId) {
                return null;
            }
            $subscriptionInvoicePaid = data_get($subscription, 'stripe_invoice_paid');
            if (!$subscriptionInvoicePaid) {
                return null;
            }
            $subscriptionConfigs = collect(config('subscription'));
            $stripePlanId = null;
            $subscriptionConfigs->map(function ($value, $key) use ($subscriptionPlanId, &$stripePlanId) {
                if ($value === $subscriptionPlanId) {
                    $stripePlanId = $key;
                };
            })->first();
            if ($stripePlanId) {
                return str($stripePlanId)->after('stripe_price_id_')->before('_')->lower();
            }
        }
        return 'zero';
    }
}
