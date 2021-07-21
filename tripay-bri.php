<?php

/**
 * Plugin integrasi Tripay Payment Gateway dengan aMember.
 * Tested on aMember Pro v6.2.10
 *
 * Copyright (c) 2021 PT Trijaya Digital Group.
 *
 * @link https://tripay.co.id
 *
 */

require_once 'Tripay/Base.php';

class Am_Paysystem_TripayBri extends Am_Paysystem_TripayBase
{
    protected $tripayPaymentMethod = null;
    protected $tripayPaymentMethodName = null;

    public function __construct(Am_Di $dependency, array $config)
    {
        $this->tripayPaymentMethod = 'BRIVA';
        $this->tripayPaymentMethodName = 'Bank BRI';

        parent::__construct($dependency, $config);
    }
}
