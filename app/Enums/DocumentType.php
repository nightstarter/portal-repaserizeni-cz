<?php

namespace App\Enums;

enum DocumentType: string
{
    case SUPPLIER_INVOICE = 'SUPPLIER_INVOICE';
    case SUPPLIER_CREDIT_NOTE = 'SUPPLIER_CREDIT_NOTE';
    case BUYBACK_INVOICE_TO_POLAND = 'BUYBACK_INVOICE_TO_POLAND';
}
