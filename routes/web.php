<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use App\Providers\RouteServiceProvider;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// add code below for using sso login
Route::get('/sso-login', function (Request $request) {
    $query = http_build_query([
        'client_id' => env('CLIENT_ID'),
        'redirect_uri' => env('APP_URL') . '/redirect',
    ]);

    return redirect('http://127.0.0.1:8080/v1/login?' . $query);
})->name('sso-login');

Route::get('/redirect', function(Request $request){
    $request->session()->put('state', $state = Str::random(40));

    $query = http_build_query([
        'client_id' => env('CLIENT_ID'),
        'redirect_uri' => env('APP_URL') . '/callback',
        'response_type' => 'code',
        'scope' => '',
        'state' => $state,
    ]);

    return redirect(env('CIAM_URL') . '/oauth/authorize?' . $query);
});

Route::get('/callback', function (Request $request) {
    $state = $request->session()->pull('state');

    throw_unless(
        strlen($state) > 0 && $state === $request->state,
        InvalidArgumentException::class,
        'Invalid state value.'
    );

    $response = Http::asForm()->post(env('CIAM_URL') . '/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => env('CLIENT_ID'),
        'client_secret' => env('CLIENT_SECRET'),
        'redirect_uri' => env('APP_URL') . '/callback',
        'code' => $request->code,
    ]);

    $request->session()->put('access_token', $access_token = $response->json('access_token'));
    // fetch user from sso server
    $user = Http::withHeaders([
        'Accept' => "application/json",
        'Authorization' => 'Bearer ' . $access_token
    ])->get(env('CIAM_URL') . '/get-user');

    // konversi token oauth ke session untuk login
    Auth::guard('web')->loginUsingId($user->json('id'));

    return redirect(RouteServiceProvider::HOME);
});

Route::post('/logout-sso', function (Request $request) {

    $token = $request->session()->get('access_token');

    // logout from CIAM server
    Http::withHeaders([
        'Accept' => 'application/json',
        'Authorization' => 'Bearer ' . $token
    ])->get(env('CIAM_URL') . '/logout');

    // logout dan generate session id from client
    Auth::guard('web')->logout();

    $request->session()->invalidate();

    $request->session()->regenerateToken();

    return redirect('/');
    
})->name('logout-sso');

require __DIR__.'/auth.php';
