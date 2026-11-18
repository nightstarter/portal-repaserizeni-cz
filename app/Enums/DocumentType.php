<?php

namespace App\Enums;

enum DocumentType: string
{
    case SUPPLIER_INVOICE = 'SUPPLIER_INVOICE';
    case SUPPLIER_CREDIT_NOTE = 'SUPPLIER_CREDIT_NOTE';
}
