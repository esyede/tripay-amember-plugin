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

class Am_Paysystem_TripayMuamalat extends Am_Paysystem_TripayBase
{
    protected $tripayPaymentMethod = null;
    protected $tripayPaymentMethodName = null;

    public function __construct(Am_Di $dependency, array $config)
    {
        $this->tripayPaymentMethod = 'MUAMALATVA';
        $this->tripayPaymentMethodName = 'Bank Muamalat';

        parent::__construct($dependency, $config);
    }
}
