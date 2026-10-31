<?php

namespace App\Actions\Auth;

use Auth;

class LoginAction
{
  public function execute(string $email, string $password): bool
  {
      return Auth::attempt([
          'email' => $email,
          'password' => $password,
          'status' => 'active',
      ]);
  }
}
