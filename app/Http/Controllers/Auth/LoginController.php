<?php
/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2021. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://opensource.org/licenses/AAL
 */

namespace App\Http\Controllers\Auth;

use App\DataMapper\Analytics\LoginFailure;
use App\DataMapper\Analytics\LoginSuccess;
use App\Events\User\UserLoggedIn;
use App\Http\Controllers\BaseController;
use App\Http\Controllers\Controller;
use App\Jobs\Account\CreateAccount;
use App\Jobs\Company\CreateCompanyToken;
use App\Jobs\Util\SystemLogger;
use App\Libraries\MultiDB;
use App\Libraries\OAuth\OAuth;
use App\Libraries\OAuth\Providers\Google;
use App\Models\Client;
use App\Models\CompanyToken;
use App\Models\CompanyUser;
use App\Models\SystemLog;
use App\Models\User;
use App\Transformers\CompanyUserTransformer;
use App\Utils\Ninja;
use App\Utils\Traits\UserSessionAttributes;
use Google_Client;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Turbo124\Beacon\Facades\LightLogs;

class LoginController extends BaseController
{
    /**
      * @OA\Tag(
      *     name="login",
      *     description="Authentication",
      *     @OA\ExternalDocumentation(
      *         description="Find out more",
      *         url="http://docs.invoiceninja.com"
      *     )
      * )
      */

    use AuthenticatesUsers;
    use UserSessionAttributes;

    protected $entity_type = CompanyUser::class;

    protected $entity_transformer = CompanyUserTransformer::class;

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = '/dashboard';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Once the user is authenticated, we need to set
     * the default company into a session variable.
     *
     * @param Request $request
     * @param User $user
     * @return void
     * deprecated .1 API ONLY we don't need to set any session variables
     */
    public function authenticated(Request $request, User $user) : void
    {
        //$this->setCurrentCompanyId($user->companies()->first()->account->default_company_id);
    }

    /**
     * Login via API.
     *
     * @param Request $request The request
     *
     * @return     Response|User Process user login.
     *
     *
     * @throws \Illuminate\Validation\ValidationException
     * @OA\Post(
     *      path="/api/v1/login",
     *      operationId="postLogin",
     *      tags={"login"},
     *      summary="Attempts authentication",
     *      description="Returns a CompanyUser object on success",
     *      @OA\Parameter(ref="#/components/parameters/X-Api-Secret"),
     *      @OA\Parameter(ref="#/components/parameters/X-Api-Token"),
     *      @OA\Parameter(ref="#/components/parameters/X-Requested-With"),
     *      @OA\Parameter(ref="#/components/parameters/include"),
     *      @OA\Parameter(ref="#/components/parameters/include_static"),
     *      @OA\Parameter(ref="#/components/parameters/clear_cache"),
     *      @OA\RequestBody(
     *         description="User credentials",
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 type="object",
     *                 @OA\Property(
     *                     property="email",
     *                     description="The user email address",
     *                     type="string",
     *                 ),
     *                 @OA\Property(
     *                     property="password",
     *                     example="1234567",
     *                     description="The user password must meet minimum criteria ~ >6 characters",
     *                     type="string"
     *                 )
     *             )
     *         )
     *     ),
     *      @OA\Response(
     *          response=200,
     *          description="The Company User response",
     *          @OA\Header(header="X-MINIMUM-CLIENT-VERSION", ref="#/components/headers/X-MINIMUM-CLIENT-VERSION"),
     *          @OA\Header(header="X-RateLimit-Remaining", ref="#/components/headers/X-RateLimit-Remaining"),
     *          @OA\Header(header="X-RateLimit-Limit", ref="#/components/headers/X-RateLimit-Limit"),
     *          @OA\JsonContent(ref="#/components/schemas/CompanyUser"),
     *       ),
     *       @OA\Response(
     *          response=422,
     *          description="Validation error",
     *          @OA\JsonContent(ref="#/components/schemas/ValidationError"),
     *       ),
     *       @OA\Response(
     *           response="default",
     *           description="Unexpected Error",
     *           @OA\JsonContent(ref="#/components/schemas/Error"),
     *       ),
     *     )
     */
    public function apiLogin(Request $request)
    {
        $this->forced_includes = ['company_users'];

        $this->validateLogin($request);

        if ($this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);

            return response()
            ->json(['message' => 'Too many login attempts, you are being throttled'], 401)
            ->header('X-App-Version', config('ninja.app_version'))
            ->header('X-Api-Version', config('ninja.minimum_client_version'));
        }

        if ($this->attemptLogin($request)) {

            LightLogs::create(new LoginSuccess())
                ->increment()
                ->batch();

            $user = $this->guard()->user();

            event(new UserLoggedIn($user, $user->account->default_company, Ninja::eventVars($user->id)));

            //if user has 2fa enabled - lets check this now:

            if($user->google_2fa_secret && $request->has('one_time_password'))
            {
                $google2fa = new Google2FA();

                if(strlen($request->input('one_time_password')) == 0 || !$google2fa->verifyKey(decrypt($user->google_2fa_secret), $request->input('one_time_password')))
                {
                    return response()
                    ->json(['message' => ctrans('texts.invalid_one_time_password')], 401)
                    ->header('X-App-Version', config('ninja.app_version'))
                    ->header('X-Api-Version', config('ninja.minimum_client_version'));
                }

            }
            elseif($user->google_2fa_secret && !$request->has('one_time_password')) {
                
                    return response()
                    ->json(['message' => ctrans('texts.invalid_one_time_password')], 401)
                    ->header('X-App-Version', config('ninja.app_version'))
                    ->header('X-Api-Version', config('ninja.minimum_client_version'));
            }

            $user->setCompany($user->account->default_company);

            $timeout = $user->company()->default_password_timeout;

            if($timeout == 0)
                $timeout = 30*60*1000*1000;
            else
                $timeout = $timeout/1000;

            Cache::put($user->hashed_id.'_logged_in', Str::random(64), $timeout);

            $cu = CompanyUser::query()
                  ->where('user_id', auth()->user()->id);

            if(!$cu->exists())
                return response()->json(['message' => 'User not linked to any companies'], 403);

            $cu->first()->account->companies->each(function ($company) use($cu, $request){

                if($company->tokens()->where('is_system', true)->count() == 0)
                {
                    CreateCompanyToken::dispatchNow($company, $cu->first()->user, $request->server('HTTP_USER_AGENT'));
                }

            });

            return $this->timeConstrainedResponse($cu);
            // return $this->listResponse($cu);

        } else {

            LightLogs::create(new LoginFailure())
                ->increment()
                ->batch();

            SystemLogger::dispatch(
                request()->getClientIp(),
                SystemLog::CATEGORY_SECURITY,
                SystemLog::EVENT_USER,
                SystemLog::TYPE_LOGIN_FAILURE,
                Client::first(),
            );

            $this->incrementLoginAttempts($request);

            return response()
            ->json(['message' => ctrans('texts.invalid_credentials')], 401)
            ->header('X-App-Version', config('ninja.app_version'))
            ->header('X-Api-Version', config('ninja.minimum_client_version'));

        }
    }

    /**
     * Refreshes the data feed with the current Company User.
     *
     * @param Request $request
     * @return     CompanyUser Refresh Feed.
     *
     *
     * @OA\Post(
     *      path="/api/v1/refresh",
     *      operationId="refresh",
     *      tags={"refresh"},
     *      summary="Refreshes the dataset",
     *      description="Refreshes the dataset",
     *      @OA\Parameter(ref="#/components/parameters/X-Api-Secret"),
     *      @OA\Parameter(ref="#/components/parameters/X-Api-Token"),
     *      @OA\Parameter(ref="#/components/parameters/X-Requested-With"),
     *      @OA\Parameter(ref="#/components/parameters/include"),
     *      @OA\Parameter(ref="#/components/parameters/include_static"),
     *      @OA\Parameter(ref="#/components/parameters/clear_cache"),
     *      @OA\Response(
     *          response=200,
     *          description="The Company User response",
     *          @OA\Header(header="X-MINIMUM-CLIENT-VERSION", ref="#/components/headers/X-MINIMUM-CLIENT-VERSION"),
     *          @OA\Header(header="X-RateLimit-Remaining", ref="#/components/headers/X-RateLimit-Remaining"),
     *          @OA\Header(header="X-RateLimit-Limit", ref="#/components/headers/X-RateLimit-Limit"),
     *          @OA\JsonContent(ref="#/components/schemas/CompanyUser"),
     *       ),
     *       @OA\Response(
     *          response=422,
     *          description="Validation error",
     *          @OA\JsonContent(ref="#/components/schemas/ValidationError"),
     *       ),
     *       @OA\Response(
     *           response="default",
     *           description="Unexpected Error",
     *           @OA\JsonContent(ref="#/components/schemas/Error"),
     *       ),
     *     )
     */
    public function refresh(Request $request)
    {
        $company_token = CompanyToken::whereRaw('BINARY `token`= ?', [$request->header('X-API-TOKEN')])->first();

        $cu = CompanyUser::query()
                          ->where('user_id', $company_token->user_id);


        $cu->first()->account->companies->each(function ($company) use($cu, $request){

            if($company->tokens()->where('is_system', true)->count() == 0)
            {
                CreateCompanyToken::dispatchNow($company, $cu->first()->user, $request->server('HTTP_USER_AGENT'));
            }
        });


        if($request->has('current_company') && $request->input('current_company') == 'true')
          $cu->where("company_id", $company_token->company_id);

        return $this->refreshResponse($cu);
    }

    /**
     * A client side authentication has taken place.
     * We now digest the token and confirm authentication with
     * the authentication server, the correct user object
     * is returned to us here and we send back the correct
     * user object payload - or error.
     *
     * This can be extended to a create route also - need to pass a ?create query parameter and
     * then process the signup
     *
     * return   User $user
     */
    public function oauthApiLogin()
    {
        if (request()->input('provider') == 'google') {
            return $this->handleGoogleOauth();
        }

        return response()
        ->json(['message' => 'Provider not supported'], 400)
        ->header('X-App-Version', config('ninja.app_version'))
        ->header('X-Api-Version', config('ninja.minimum_client_version'));
    }

    private function handleGoogleOauth()
    {
        $user = false;

        $google = new Google();

        $user = $google->getTokenResponse(request()->input('id_token'));

        if (is_array($user)) {

            $query = [
                'oauth_user_id' => $google->harvestSubField($user),
                'oauth_provider_id'=> 'google',
            ];

            if ($existing_user = MultiDB::hasUser($query)) {

                Auth::login($existing_user, true);
                $existing_user->setCompany($existing_user->account->default_company);

                $timeout = $existing_user->company()->default_password_timeout;

                if($timeout == 0)
                    $timeout = 30*60*1000*1000;
                else
                    $timeout = $timeout/1000;


                Cache::put($existing_user->hashed_id.'_logged_in', Str::random(64), $timeout);

                $cu = CompanyUser::query()
                                  ->where('user_id', auth()->user()->id);

                $cu->first()->account->companies->each(function ($company) use($cu){

                    if($company->tokens()->where('is_system', true)->count() == 0)
                    {
                        CreateCompanyToken::dispatchNow($company, $cu->first()->user, request()->server('HTTP_USER_AGENT'));
                    }
                });

                return $this->timeConstrainedResponse($cu);
                
            }
        }

        if ($user) {
            
            $name = OAuth::splitName($google->harvestName($user));

            $new_account = [
                'first_name' => $name[0],
                'last_name' => $name[1],
                'password' => '',
                'email' => $google->harvestEmail($user),
                'oauth_user_id' => $google->harvestSubField($user),
                // 'oauth_user_token' => $token,
                // 'oauth_user_refresh_token' => $refresh_token,
                'oauth_provider_id' => 'google',
            ];

            MultiDB::setDefaultDatabase();

            $account = CreateAccount::dispatchNow($new_account);

            Auth::login($account->default_company->owner(), true);

            auth()->user()->email_verified_at = now();
            auth()->user()->save();

            $timeout = auth()->user()->company()->default_password_timeout;

                if($timeout == 0)
                    $timeout = 30*60*1000*1000;
                else
                    $timeout = $timeout/1000;


            Cache::put(auth()->user()->hashed_id.'_logged_in', Str::random(64), $timeout);

            $cu = CompanyUser::whereUserId(auth()->user()->id);

            $cu->first()->account->companies->each(function ($company) use($cu){

                if($company->tokens()->where('is_system', true)->count() == 0)
                {
                    CreateCompanyToken::dispatchNow($company, $cu->first()->user, request()->server('HTTP_USER_AGENT'));
                }
            });

            return $this->timeConstrainedResponse($cu);
        }

        return response()
        ->json(['message' => ctrans('texts.invalid_credentials')], 401)
        ->header('X-App-Version', config('ninja.app_version'))
        ->header('X-Api-Version', config('ninja.minimum_client_version'));
    }
}
