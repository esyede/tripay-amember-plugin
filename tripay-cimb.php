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

class Am_Paysystem_TripayCimb extends Am_Paysystem_TripayBase
{
    public function __construct(Am_Di $dependency, array $config)
    {
        $this->tripayPaymentMethod = 'CIMBVA';
        $this->tripayPaymentMethodName = 'Bank CIMB';

        parent::__construct($dependency, $config);
    }
}
