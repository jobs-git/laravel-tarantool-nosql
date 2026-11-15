<?php

namespace Tarantool\Nosql\Migrations;

use Illuminate\Database\Migrations\Migration as BaseMigration;

abstract class Migration extends BaseMigration
{
    abstract public function up(): void;
    abstract public function down(): void;
}