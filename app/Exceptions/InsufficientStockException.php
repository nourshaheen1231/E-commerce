<?php
// app/Exceptions/InsufficientStockException.php

namespace App\Exceptions;

use Exception;

class InsufficientStockException extends Exception
{
    protected string $productName;

    public function __construct(string $message, string $productName)
    {
        parent::__construct($message);
        $this->productName = $productName;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }
}
