<?php

namespace App\Enums;

enum CoreInspectionPieceResult: string
{
    case CORRECT_PIECE = 'CORRECT_PIECE';
    case DIFFERENT_PIECE = 'DIFFERENT_PIECE';
}
