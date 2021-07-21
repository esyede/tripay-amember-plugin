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

class Am_Paysystem_TripayAlfamidi extends Am_Paysystem_TripayBase
{
    public function __construct(Am_Di $dependency, array $config)
    {
        $this->tripayPaymentMethod = 'ALFAMIDI';
        $this->tripayPaymentMethodName = 'Alfamidi';

        parent::__construct($dependency, $config);
    }
}
