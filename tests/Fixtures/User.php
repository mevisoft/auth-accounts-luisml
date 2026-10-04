<?php

namespace LuisML\AccountsClient\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use LuisML\AccountsClient\Concerns\HasAccountsRoles;

class User extends Authenticatable
{
    use HasAccountsRoles;

    protected $table = 'users';

    protected $guarded = [];

    protected $hidden = ['password'];
}
