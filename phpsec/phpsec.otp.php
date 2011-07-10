<?php
/**
  phpSec - A PHP security library

  @author    Audun Larsen <larsen@xqus.com>
  @copyright Copyright (c) Audun Larsen, 2011
  @link      https://github.com/xqus/phpSec
  @license   http://opensource.org/licenses/mit-license.php The MIT License
  @package   phpSec
 */

/**
 * Provides one time password functionality. This code is experimental.
 */
class phpsecOtp {
  const HASH_TYPE = 'sha256';
  /**
   * Generate a one-time-password (OTP). The password is only valid for a given time,
   * and must be delivered to the user instantly. The password is also only valid
   * for the current session.
   *
   * @param string $action
   *   The action to generate a OTP for. This should be as specific as possible.
   *   Used to ensure that the OTP is used for the intended action.
   *
   * @param array $data
   *   Optional array of data that belongs to $action. Used to ensure that the action
   *   is performed with the same data as when the OTP was generated.
   *
   * @param integer $length
   *   OTP length.
   *
   * @param integer $ttl
   *   Time to live for the OTP. In seconds.
   *
   * @return string
   *   One time password that should be delivered to the user.
   *
   */
  public static function generate($action, $data = null, $length = 6, $ttl = 480) {
    $otp['pw'] = phpsecRand::str($length);
    if($data !== null) {
      $otp['hash'] = hash(self::HASH_TYPE, serialize($data));
    }
    phpsecCache::cacheSet('otp-'.$action, $otp, $ttl);

    return $otp['pw'];
  }

  /**
   * Validate a one-time-password.
   *
   * @param strgin $otp
   *   OTP supplied by user.
   *
   * @param string $action
   *   See phpsecOtp::generate().
   *
   * @param array $data
   *   See phpsecOtp::generate().
   *
   */
  public static function validate($otp, $action, $data = null) {
    $cache = phpsecCache::cacheGet('otp-'.$action);
    /* This is totally backwards. We check for what should not have been and
     * return false if we stubmle across something fishy. Unless something good happened,
     * and we somehow did't find anything wrong. Then we return true. But if something really
     * bad happens we still return false. */
    if($cache !== false) {
      if($cache['pw'] !== $otp) {
        return false;
      } elseif(isset($cache['hash']) && $cache['hash'] !== hash(self::HASH_TYPE, serialize($data))) {
        return false;
      }
      return true;
    }
    return false;
  }

  /**
   * Create a list of 64 pre shared one-time-passwords,
   * or a so called password card.
   *
   * This differs from phpsecOtp::generate() because passwords generated by
   * this function is saved permanent and can be validated on a later time.
   */
  public static function cardGenerate() {
    $card['list'] = array();
    for($i = 0; $i < 64; $i++) {
      $card['list'][$i]   = phpsecRand::str(6);
      $card['usable'][$i] = true;
    }

    $card = self::cardHash($card);
    self::cardSave($card);

    return $card['id'];
  }

  /**
   * Validates a pre shared one-time-password.
   *
   * @param string $cardId
   *   Card ID.
   *
   * @param integer $selected
   *   OTP ID the user is expected to use. Usually
   *   provided by phpsecOtp::cardSelect().
   *
   * @param string $otp
   *   The password provided by the user.
   *
   * @return bolean
   */
  public static function cardValidate($cardId, $selected, $otp) {
    $card = self::cardLoad($cardId);
    if(isset($card['usable'][$selected]) && $card['usable'][$selected] === true) {
      if($card['list'][$selected] == $otp) {
        unset($card['usable'][$selected]);

        $card = self::cardHash($card);
        self::cardSave($card);

        return true;
      }
    }
    return false;
  }

  /**
   * Select a pre shared OTP from a list that a user can use.
   *
   * @param string $cardId
   *   Card ID to select a OTP from.
   *
   * @return integer
   *   OTP ID of a available OTP.
   */
  public static function cardSelect($cardId) {
    $card = self::cardLoad($cardId);

    $available = array_keys($card['usable']);
    $selected  = phpsecRand::int(0, count($available)-1);

    return $available[$selected];
  }
  /**
   * Load a password card.
   *
   * @param string $cardId
   *   Card ID.
   *
   * @return array
   *   A array containing the card data.
   */
  public static function cardLoad($cardId) {
    $filename = phpsec::$_datadir.'/otp-card-'.$cardId;
    if(file_exists($filename)) {
      $card = json_decode(file_get_contents($filename), true);
      if($card['hash'] !== hash(self::HASH_TYPE, $card['list'])) {
        return false;
      }
      $card['list'] = json_decode(base64_decode($card['list']), true);
      return $card;
    }
    return false;
  }

  /**
   * Get the number of unused OTPs on a password card.
   *
   * @param string $cardId
   *   Card ID.
   *
   * @return integer
   *   Number of unused OTPs.
   */
  public static function cardRemaining($cardId) {
    $card = self::cardLoad($cardId);

    return count($card['usable']);
  }

  /**
   * Save a password card. Can only be called after phpsecOtp::cardHash().
   *
   * @param array $card
   *   Array containing a already hashed card.
   *
   * @return bolean
   *   Returns true on success and false on error.
   */
  private static function cardSave($card) {
    /* TODO: Encrypt before saving. */
    $fp = @fopen(phpsec::$_datadir.'/otp-card-'.$card['id'], 'w');
    if($fp !== false) {
      /* We are trying to lock the file, altough it's really not that fu*king
       * important. We are truncating the file with the 'w' option to fopen()
       * anyways. But if for some reason someone should see this. Just don't
       * pay to much attention to it. And yeah I know  we should check if
       * the lock was successfull, and yes I know I probably used more time
       * writing this comment than I would writing the code doing that.
       * So sue me! */
      flock($fp, LOCK_EX);
      fwrite($fp, json_encode($card));
      flock($fp, LOCK_UN);
      fclose($fp);
      return true;
    }
    return false;
  }

  /**
   * Prepeare the password card for saving.
   * Must be called before phpsecOtp::cardSave().
   *
   * @param array $card
   *   Array containing the card data to hash.
   *
   * @return array
   *   Arrray containing hashed card data. Ready for phpsecOtp::cardSave().
   */
  private static function cardHash($card) {
    /* We are encoding the password list just because we want to make
     * the file look nice, and to avoid bugs with special characters. */
    $card['list'] = base64_encode(json_encode($card['list']));
    $card['hash'] = hash(self::HASH_TYPE, $card['list']);
    $card['id']   = substr($card['hash'], 0, 12);

    return $card;
  }
}