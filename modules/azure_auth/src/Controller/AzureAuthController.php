<?php

declare(strict_types=1);

namespace Drupal\azure_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\user\Entity\User;
use Drupal\Core\Url;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Returns responses for Azure auth routes.
 */
final class AzureAuthController extends ControllerBase
{

  /**
   * Builds the response.
   */
  public function __invoke(): array
  {
    $build['content'] = [
      '#type' => 'item',
      '#markup' => $this->t('It works!'),
    ];

    return $build;
  }


  /**
   * Redirects users to the Azure AD login.
   */
  public function login(Request $request)
  {
    $destination = $request->query->get('destination');
    if ($destination) {
      $request->getSession()->set('azure_login_destination', $destination);
    }

    $tenant_id = \Drupal::config('azure_auth.azure_configs')->get('tenant_id');
    $clientId = \Drupal::config('azure_auth.azure_configs')->get('client_id');
    $redirectUri = Url::fromRoute('azure_auth.callback', [], ['absolute' => TRUE])->toString();

    // Generate state (CSRF) and nonce (JWT replay) tokens.
    $state = bin2hex(random_bytes(16));
    $nonce = bin2hex(random_bytes(16));
    $request->getSession()->set('azure_oauth_state', $state);
    $request->getSession()->set('azure_oauth_nonce', $nonce);

    $authorizationUrl = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/authorize?" . http_build_query([
      'client_id' => $clientId,
      'response_type' => 'code',
      'redirect_uri' => $redirectUri,
      'response_mode' => 'query',
      'scope' => 'openid email profile',
      'state' => $state,
      'nonce' => $nonce,
    ]);

    $response = new TrustedRedirectResponse($authorizationUrl);
    $response->setMaxAge(0);
    return $response;
  }

  /**
   * Handles Azure AD callback and authenticates users.
   */
  public function callback(Request $request)
  {
    // Verify state parameter to prevent CSRF attacks.
    $sessionState = $request->getSession()->get('azure_oauth_state');
    $request->getSession()->remove('azure_oauth_state');
    $returnedState = $request->query->get('state');
    if (!$sessionState || !hash_equals($sessionState, (string) $returnedState)) {
      \Drupal::messenger()->addError($this->t('Authentication failed. Please contact support.'));
      \Drupal::logger('azure_auth')->error('Azure login failed: invalid or missing state parameter (possible CSRF attack).');
      throw new AccessDeniedHttpException();
    }

    $sessionNonce = $request->getSession()->get('azure_oauth_nonce');
    $request->getSession()->remove('azure_oauth_nonce');

    $destination = $request->getSession()->get('azure_login_destination') ?? '';
    $request->getSession()->remove('azure_login_destination');

    $baseUrl = \Drupal::request()->getSchemeAndHttpHost();
    $basePath = \Drupal::request()->getBasePath();
    $path = rtrim($baseUrl . $basePath, '/');

    $tenant_id = \Drupal::config('azure_auth.azure_configs')->get('tenant_id');
    $clientId = \Drupal::config('azure_auth.azure_configs')->get('client_id');
    $clientSecret = \Drupal::config('azure_auth.azure_configs')->get('client_secret');
    $userinfo_endpoint = \Drupal::config('azure_auth.azure_configs')->get('userinfo_endpoint');
    $redirectUri = Url::fromRoute('azure_auth.callback', [], ['absolute' => TRUE])->toString();

    $code = $request->query->get('code');
    if (!$code) {
      \Drupal::messenger()->addError($this->t('Authentication failed. Please contact support.'));
      \Drupal::logger('azure_auth')->error("Azure login failed: no authorization code provided.\nRequest info:\n<pre>@data</pre>", [
        '@data' => print_r([
          'destination' => $destination,
          'request_uri' => $request->getUri(),
          'query_params' => $request->query->all(),
        ], TRUE),
      ]);
      throw new AccessDeniedHttpException();
    }

    // Exchange authorization code for tokens.
    $tokenUrl = "https://login.microsoftonline.com/{$tenant_id}/oauth2/v2.0/token";
    try {
      $response = \Drupal::httpClient()->post($tokenUrl, [
        'form_params' => [
          'client_id' => $clientId,
          'client_secret' => $clientSecret,
          'code' => $code,
          'redirect_uri' => $redirectUri,
          'grant_type' => 'authorization_code',
        ],
      ]);
      $data = json_decode($response->getBody()->getContents(), TRUE);
    } catch (RequestException $e) {
      \Drupal::messenger()->addError($this->t('Authentication failed. Please contact support.'));
      \Drupal::logger('azure_auth')->error('Azure login failed: token request error. @message', ['@message' => $e->getMessage()]);
      throw new AccessDeniedHttpException();
    }

    if (!isset($data['access_token'])) {
      \Drupal::messenger()->addError($this->t('Authentication failed. Please contact support.'));
      \Drupal::logger('azure_auth')->error("Azure login failed: access token not found in response.\nToken response:\n<pre>@data</pre>", [
        '@data' => print_r($data, TRUE),
      ]);
      throw new AccessDeniedHttpException();
    }

    // Validate the id_token JWT before trusting any identity data.
    if (!isset($data['id_token'])) {
      \Drupal::messenger()->addError($this->t('Authentication failed. Please contact support.'));
      \Drupal::logger('azure_auth')->error('Azure login failed: id_token missing from token response.');
      throw new AccessDeniedHttpException();
    }

    try {
      $idTokenPayload = $this->validateIdToken(
        $data['id_token'],
        $tenant_id,
        $clientId,
        $sessionNonce
      );
    } catch (\RuntimeException $e) {
      \Drupal::messenger()->addError($this->t('Authentication failed. Please contact support.'));
      \Drupal::logger('azure_auth')->error('Azure login failed: id_token validation error. @message', ['@message' => $e->getMessage()]);
      throw new AccessDeniedHttpException();
    }

    // Get user info data.
    try {
      $userResponse = \Drupal::httpClient()->get($userinfo_endpoint, [
        'headers' => [
          'Authorization' => 'Bearer ' . $data['access_token'],
          'Content-Type' => 'application/json',
        ],
      ]);
      $userData = json_decode($userResponse->getBody()->getContents(), TRUE);
    } catch (RequestException $e) {
      \Drupal::messenger()->addError($this->t('Authentication failed. Please contact support.'));
      \Drupal::logger('azure_auth')->error('Azure login failed: userinfo request error. @message', ['@message' => $e->getMessage()]);
      throw new AccessDeniedHttpException();
    }

//Need  User.Read in the scope
    // $photoBase64 = NULL;
    // $pictureUrl = $userData['picture'] ?? NULL;

    // if ($pictureUrl) {
    //   try {
    //     $photoResponse = \Drupal::httpClient()->get($pictureUrl, [
    //       'headers' => [
    //         'Authorization' => 'Bearer ' . $data['access_token'],
    //       ],
    //     ]);
    //     $photoData = $photoResponse->getBody()->getContents();
    //     $photoBase64 = 'data:image/jpeg;base64,' . base64_encode($photoData);
    //   } catch (RequestException $e) {
    //     // Pas bloquant, certains utilisateurs n'ont pas de photo
    //     \Drupal::logger('azure_auth')->warning('Could not fetch Azure profile picture. @message', ['@message' => $e->getMessage()]);
    //   }
    // }


    $email = $userData['email'] ?? NULL;
    $family_name = $userData['family_name'] ?? '';
    $given_name = $userData['given_name'] ?? '';
    $name = ($family_name && $given_name) ? $given_name . '.' . $family_name : $email;

    if (!$email) {
      \Drupal::messenger()->addError($this->t('Authentication failed. Please contact support.'));
      \Drupal::logger('azure_auth')->error("Azure user info missing email.\nUser data:\n<pre>@data</pre>", [
        '@data' => print_r($userData, TRUE),
      ]);
      throw new AccessDeniedHttpException();
    }

    // Load or create the Drupal user.
    $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['mail' => $email]);
    $user = reset($users);

    if ($user) {
      if ($user->getAccountName() !== $name) {
        $user->setUsername($name);
        $user->save();
      }
    } else {
      $user = User::create([
        'name' => $name,
        'mail' => $email,
        'status' => 1,
        'roles' => ['authenticated', 'bank_staff'],
      ]);
      $user->save();
    }

    user_login_finalize($user);

    $redirectUrl = $destination ? $path . '/' . ltrim($destination, '/') : $path . '/';
    return new TrustedRedirectResponse($redirectUrl);
  }

  /**
   * Validates an Azure AD id_token JWT.
   *
   * Fetches Azure's public JWKS, finds the matching key by 'kid',
   * verifies the RSA signature, then checks claims (iss, aud, exp, nbf, nonce).
   *
   * @param string $idToken  Raw JWT string.
   * @param string $tenantId Azure tenant ID.
   * @param string $clientId OAuth client ID (expected audience).
   * @param string|null $nonce Nonce stored in session at login.
   *
   * @return array Decoded JWT payload.
   *
   * @throws \RuntimeException on any validation failure.
   */
  private function validateIdToken(string $idToken, string $tenantId, string $clientId, ?string $nonce): array
  {
    // 1. Split and base64url-decode the JWT parts.

    $parts = explode('.', $idToken);

    if (count($parts) !== 3) {
      throw new \RuntimeException('id_token is not a valid JWT (expected 3 parts).');
    }

    $header  = json_decode($this->base64UrlDecode($parts[0]), TRUE);
    $payload = json_decode($this->base64UrlDecode($parts[1]), TRUE);

    if (!$header || !$payload) {
      throw new \RuntimeException('id_token JWT header or payload could not be decoded.');
    }

    $algorithm = $header['alg'] ?? '';
    if ($algorithm !== 'RS256') {
      throw new \RuntimeException("id_token uses unsupported algorithm: {$algorithm}. Expected RS256.");
    }

    $kid = $header['kid'] ?? NULL;
    if (!$kid) {
      throw new \RuntimeException('id_token JWT header is missing the "kid" field.');
    }

    // 2. Fetch Azure's public JWKS and find the matching key.
    $jwksUri = "https://login.microsoftonline.com/{$tenantId}/discovery/v2.0/keys";
    try {
      $jwksResponse = \Drupal::httpClient()->get($jwksUri);
      $jwks = json_decode($jwksResponse->getBody()->getContents(), TRUE);
    } catch (RequestException $e) {
      throw new \RuntimeException('Failed to fetch Azure JWKS: ' . $e->getMessage());
    }

    $publicKey = NULL;

    foreach ($jwks['keys'] ?? [] as $key) {
      if (($key['kid'] ?? '') === $kid && ($key['kty'] ?? '') === 'RSA') {
        $publicKey = $this->jwkToPublicKey($key);
        break;
      }
    }

    if (!$publicKey) {
      throw new \RuntimeException("No matching RSA public key found for kid: {$kid}.");
    }

    // 3. Verify the JWT signature using OpenSSL.
    $signingInput = $parts[0] . '.' . $parts[1];
    $signature    = $this->base64UrlDecode($parts[2]);

    $verified = openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);
    if ($verified !== 1) {
      throw new \RuntimeException('id_token signature verification failed.');
    }

    // 4. Validate standard claims.
    $now = time();

    $issuer = $payload['iss'] ?? '';
    $expectedIssuer = "https://login.microsoftonline.com/{$tenantId}/v2.0";
    if ($issuer !== $expectedIssuer) {
      throw new \RuntimeException("id_token issuer mismatch. Got: {$issuer}");
    }

    $audience = $payload['aud'] ?? '';
    if ($audience !== $clientId) {
      throw new \RuntimeException("id_token audience mismatch. Got: {$audience}");
    }

    $exp = $payload['exp'] ?? 0;
    if ($now >= $exp) {
      throw new \RuntimeException('id_token has expired.');
    }

    $nbf = $payload['nbf'] ?? 0;
    if ($now < $nbf) {
      throw new \RuntimeException('id_token is not yet valid (nbf claim).');
    }

    // 5. Validate nonce to prevent token replay attacks.
    if ($nonce !== NULL) {
      $tokenNonce = $payload['nonce'] ?? NULL;
      if (!$tokenNonce || !hash_equals($nonce, $tokenNonce)) {
        throw new \RuntimeException('id_token nonce mismatch (possible replay attack).');
      }
    }

    return $payload;
  }

  /**
   * Converts a JWK RSA public key (n, e) to a PEM-encoded OpenSSL key resource.
   *
   * @param array $jwk JWK key array with 'n' and 'e' base64url values.
   *
   * @return \OpenSSLAsymmetricKey
   *
   * @throws \RuntimeException if the key cannot be created.
   */
  private function jwkToPublicKey(array $jwk)
  {
    $n = $this->base64UrlDecode($jwk['n']);
    $e = $this->base64UrlDecode($jwk['e']);


    // Build the RSA public key from raw n/e components as ASN.1 DER,
    // then convert to PEM for use with openssl_verify().
    $modulus  = $this->encodeAsn1Integer($n);
    $exponent = $this->encodeAsn1Integer($e);



    // RSAPublicKey ::= SEQUENCE { modulus INTEGER, publicExponent INTEGER }
    $rsaPublicKey = "\x30" . $this->asn1Length(strlen($modulus . $exponent)) . $modulus . $exponent;

    // SubjectPublicKeyInfo with rsaEncryption OID (1.2.840.113549.1.1.1)
    // Build BIT STRING first so its variable-length prefix is computed correctly.
    $oid       = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $bitString = "\x03" . $this->asn1Length(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;
    $spki      = "\x30" . $this->asn1Length(strlen($oid) + strlen($bitString)) . $oid . $bitString;

    $pem = "-----BEGIN PUBLIC KEY-----\n"
      . chunk_split(base64_encode($spki), 64, "\n")
      . "-----END PUBLIC KEY-----\n";

    $publicKey = openssl_pkey_get_public($pem);
    if ($publicKey === FALSE) {
      throw new \RuntimeException('Failed to build OpenSSL public key from JWK.');
    }

    return $publicKey;
  }

  /**
   * Encodes a raw big-integer byte string as an ASN.1 DER INTEGER.
   */
  private function encodeAsn1Integer(string $bytes): string
  {
    // Prepend 0x00 if the high bit is set (to keep it positive).
    if (ord($bytes[0]) > 0x7f) {
      $bytes = "\x00" . $bytes;
    }
    return "\x02" . $this->asn1Length(strlen($bytes)) . $bytes;
  }

  /**
   * Encodes a length value in ASN.1 DER format.
   */
  private function asn1Length(int $length): string
  {
    if ($length < 0x80) {
      return chr($length);
    }
    $encoded = '';
    $temp = $length;
    while ($temp > 0) {
      $encoded = chr($temp & 0xff) . $encoded;
      $temp >>= 8;
    }
    return chr(0x80 | strlen($encoded)) . $encoded;
  }

  /**
   * Decodes a base64url-encoded string (RFC 4648).
   */
  private function base64UrlDecode(string $input): string
  {
    return base64_decode(strtr($input, '-_', '+/') . str_repeat('=', (4 - strlen($input) % 4) % 4));
  }


  public function drupallogin()
  {
    return [
      '#markup' => $this->t(''),
    ];
  }

  public function accessDenied()
  {
    $current_path = \Drupal::service('path.current')->getPath();
    return [
      '#theme' => 'access_denied',
      '#title' => '',
      '#path' => $current_path,
    ];
  }

  public function notFound()
  {
    $current_path = \Drupal::service('path.current')->getPath();
    return [
      '#theme' => 'not_found',
      '#title' => '',
      '#path' => $current_path,
    ];
  }
}
