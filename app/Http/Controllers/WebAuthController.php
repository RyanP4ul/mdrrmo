<?php

namespace App\Http\Controllers;

use App\Actions\Auth\LoginAction;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;

class WebAuthController extends Controller
{
  public function __construct(
      private LoginAction $loginAction
  ) {}
    
    public function authenticate(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->validated();

        $authenticated = $this->loginAction->execute(
          $credentials['email'], 
          $credentials['password']
        );
 
        if (!$authenticated) {
          return back()->withErrors([
                'email' => 'Invalid credentials.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
