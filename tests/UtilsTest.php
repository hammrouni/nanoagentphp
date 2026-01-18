<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use NanoAgent\Utils\ContextBuilder;

class UtilsTest extends TestCase
{
    public function testContextBuilder()
    {
        $base = "You are an assistant.";
        $context = [
            'User' => 'Alice',
            'Date' => '2023-01-01'
        ];

        $result = ContextBuilder::build($base, $context);

        $this->assertStringContainsString("You are an assistant.", $result);
        $this->assertStringContainsString("Context Data:", $result);
        $this->assertStringContainsString("[User]:\nAlice", $result);
        $this->assertStringContainsString("[Date]:\n2023-01-01", $result);
    }
}
