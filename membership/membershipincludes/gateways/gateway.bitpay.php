<?php
/*
Addon Name: BitPay Payment Gateway
Author: Mike Gogulski
Author URI: https://github.com/mikegogulski
Gateway ID: bitpay
*/

if (!defined('ABSPATH')) exit; // Exit if accessed directly

require_once 'php-client/bp_lib.php';

class bitpay extends Membership_Gateway {

	var $gateway = 'bitpay';
	var $title = 'BitPay payment gateway';
	var $issingle = true;
	var $ip_whitelist_cache_expire_seconds;
	var $bitpay_transaction_speeds;

	public function __construct() {
		parent::__construct();

		$this->bitpay_transaction_speeds = array(
			'high'		=>	__('High (immediate, no blockchain confirmations)', 'membership'),
			'medium'	=>	__('Medium (1 blockchain confirmation, ~10 minutes)', 'membership'),
			'low'		=>	__('Low (6 blockchain confirmations, ~1 hour)', 'membership'),
		);
		$this->ip_whitelist_cache_expire_seconds = 60 * 60 * 6;	// 6 hours
		add_action('M_gateways_settings_' . $this->gateway, array(&$this, 'mysettings'));

		// If I want to override the transactions output - then I can use this action
		//add_action('M_gateways_transactions_' . $this->gateway, array(&$this, 'mytransactions'));

		if ($this->is_active()) {
			// Subscription form gateway
			add_action('membership_purchase_button', array(&$this, 'display_subscribe_button'), 1, 3);
			// Payment return
			add_action('membership_handle_payment_return_' . $this->gateway, array(&$this, 'handle_bitpay_return'));
			//add_filter('membership_subscription_form_subscription_process', array(&$this, 'signup_free_subscription'), 10, 2);
		}
	}

        private function safe_var_dump($v) {
                if (!isset($v))
                        return '(unset)';
                if (empty($v))
                        return '(empty)';
                if ($v === true)
                        return 'TRUE';
                if ($v === false)
                        return 'FALSE';
                if ($v === NULL)
                        return 'NULL';
                $t = gettype($v);
                if ($t == 'integer' || $t == 'double')
                        return (string)$v;
                if ($t == 'string') {
                        if ($t == '')
                                return '(emptystring)';
                        else
                                return $v;
                }
                // this now covers non-empty arrays, objects and resources
                return print_r($v, true);
        }

        private function safe_format_list() {
                $r = '';
                foreach (func_get_args() as $arg)
                        $r .= self::safe_var_dump($arg);
                return $r;
        }

        private function dprint($const) {
                if ($const === true || (defined($const) && constant($const))) {
                        $frame = debug_backtrace()[1];
                        $msg = $frame['file'] . ':' . $frame['function'] . '(' . $frame['line'] . '): ';
                        $alist = func_get_args();
                        @array_shift($alist);
                        foreach ($alist as $arg)
                                $msg .= self::safe_var_dump($arg);
			$msg = trim($msg) . "\n";
			$debugfile = get_option($this->gateway . '_debugfile', false);
			if (!$debugfile)
				error_log($msg);
			else
                        	error_log($msg, 3, $debugfile);
                }
        }

	function maybe_update_bitpay_ip_whitelist() {
		$wl = get_option($this->gateway . '_ip_whitelist');
		$now = time();
		if (!$wl || !isset($wl['expires']) || $wl['expires'] > $now) {
			$r = wp_remote_get('https://bitpay.com/ipAddressList.txt');
			if (is_wp_error($r)) {
				self::dprint('Failed to fetch IP whitelist from BitPay. WP_Error: ' . self::safe_var_dump($r));
				return;
			};
			if ($r['response']['code'] != '200') {
				self::dprint('Failed to fetch IP whitelist from BitPay. Response: ' . self::safe_var_dump($r));
				return;
			}
			$ips = explode('|', trim($r['body']));
			$new_wl = array();
			$new_wl['whitelist'] = $ips;
			$new_wl['expires'] = $now + $this->ip_whitelist_cache_expire_seconds;
			update_option($this->gateway . '_ip_whitelist', $new_wl);
		}
	}

	function activate() {
		parent::activate();
		maybe_update_bitpay_ip_whitelist();
	}

	// keep the options array from bp_options.php updated with our settings
	function set_bpOptions() {
		global $bpOptions, $M_options;

		$bpOptions['apiKey'] = get_option($this->gateway . '_api_key', '');
		$bpOptions['notificationEmail'] = get_option($this->gateway . '_notification_email', '');
		$bpOptions['notificationURL'] = trailingslashit(home_url('paymentreturn/' . $this->gateway));
		$bpOptions['redirectURL'] = get_option($this->gateway . '_redirect_url', home_url(''));
		$M_options['paymentcurrency'] = get_option($this->gateway . '_pricing_currency', 'USD');
		$bpOptions['currency'] = $M_options['paymentcurrency'];
		$bpOptions['physical'] = false;
		$bpOptions['transactionSpeed'] = get_option($this->gateway . '_transaction_speed', 'low');
		$bpOptions['useLogging'] = true;
		$bpOptions['fullNotifications'] = get_option($this->gateway . '_full_notifications', false);
		$bpOptions['testnet'] = get_option($this->gateway . '_testnet', false);
		$bpOptions['debugfile'] = get_option($this->gateway . '_debugfile', false);
	}

	function get_bitpay_currencies() {
		// TODO: Handle exceptions
		$r = wp_remote_get('https://bitpay.com/currencies');
		$j = json_decode($r['body']);
		$currencies = $j->data;
		$ret = array();
		foreach ($currencies as $c) {
			// US Dollar (USD, $)
			$currency = $c->name . ' (' . $c->code . ', ' . $c->symbol . ')';
			$ret[utf8_decode($c->code)] = $currency;
		}
		asort($ret);
		return $ret;
	}

	function mysettings() {
		global $M_options;

		?>
		<div style="display: inline-block; width: 100%;" class="metabox-holder has-right-sidebar">
			<div class="inner-sidebar">
				<div class="postbox">
					<h3><span>Brought to you by<br/><a href="http://bwb.is/">Bitcoiners without Borders</a><span></h3>
					<div class="inside">
					</div> <!-- .inside -->
				</div> <!-- .postbox -->
			</div> <!-- .inner-sidebar -->
			<div id="post-body">
				<div id="post-body-content">
					<div class="postbox">
		<table class="form-table">
		<tbody>
			<tr valign="top">
			<th scope="row"><?php _e('BitPay API Key', 'membership') ?></th>
			<td><input type="text" name="bitpay_api_key" value="<?php esc_attr_e(get_option($this->gateway . "_api_key", '')); ?>" style='width: 30em;' />
			<br />
			</td>
			</tr>
			<tr valign="top">
			<th scope="row"><?php _e('BitPay payment button image', 'membership') ?></th>
			<td><input type="text" name="bitpay_button" value="<?php esc_attr_e(get_option($this->gateway . "_button", '')); ?>" style='width: 30em;' />
			<br />
			</td>
			</tr>
			<tr valign="top">
			<th scope="row"><?php _e('Payment notification email address (blank for no notifications)', 'membership') ?></th>
			<td><input type="text" name="bitpay_notification_email" value="<?php esc_attr_e(get_option($this->gateway . "_notification_email", '')); ?>" style='width: 30em;' />
			<br />
			</td>
			</tr>
			<tr valign="top">
			<th scope="row"><?php _e('Administrator email address, for errors (blank for no notifications)', 'membership') ?></th>
			<td><input type="text" name="bitpay_admin_email" value="<?php esc_attr_e(get_option($this->gateway . "_admin_email", get_option('admin_email', ''))); ?>" style='width: 30em;' />
			<br />
			</td>
			</tr>
			<tr valign="top">
			<th scope="row"><?php _e('Redirect URL (for return to site after successful purchase)', 'membership') ?></th>
			<td><input type="text" name="bitpay_redirect_url" value="<?php esc_attr_e(get_option($this->gateway . "_redirect_url", '')); ?>" style='width: 30em;' />
			<br />
			</td>
			</tr>
			<tr valign="top">
			<th scope="row"><?php _e('Pricing currency', 'membership') ?></th>
			<td><select name="bitpay_pricing_currency">
			<?php
				$pricing_currency = get_option($this->gateway . "_pricing_currency", 'USD');
				$currencies = $this->get_bitpay_currencies();
				foreach ($currencies as $code => $name) {
					echo '<option value="' . esc_attr($code) . '"';
					if ($code == $pricing_currency)
						echo ' selected="selected"';
					echo '>' . esc_html($name) . '</option>' . "\n";
				}
			?>
			</select>
			<br />
			</td>
			</tr>
			<tr valign="top">
			<th scope="row"><?php _e('BitPay transaction speed', 'membership') ?></th>
			<td><select name="bitpay_transaction_speed">
			<?php
			 	$tx_speed = get_option($this->gateway . "_transaction_speed", 'low');
				foreach ($this->bitpay_transaction_speeds as $speed => $description) {
					echo '<option value="' . esc_attr($speed) . '"';
					if ($speed == $tx_speed)
						echo ' selected="selected"';
					echo '>' . esc_html($description) . '</option>' . "\n";
				}
			?>
			</select>
			<br />
			</td>
			</tr>
			<tr valign="top">
			<th scope="row"><?php _e('Full notifications (for every change of invoice status, not just "confirmed")?', 'membership') ?></th>
			<td><input type="checkbox" name="bitpay_full_notifications" value="checked" <?php echo get_option($this->gateway . "_full_notifications") ? 'checked="checked"' : ''; ?>/>
			<br />
			</td>
			</tr>
			<tr valign="top">
			<th scope="row"><?php _e('Use the BitPay testnet development environment (test.bitpay.com)?', 'membership') ?></th>
			<td><input type="checkbox" name="bitpay_testnet" value="checked" <?php echo get_option($this->gateway . "_testnet") ? 'checked="checked"' : ''; ?>/>
			<br />
			</td>
			</tr>
			<tr valign="top">
			<th scope="row"><?php _e('Debugging logfile (full absolute server path)', 'membership') ?></th>
			<td><input type="text" name="bitpay_debugfile" value="<?php esc_attr_e(get_option($this->gateway . "_debugfile", '')); ?>" style='width: 30em;' />
			<br />
			</td>
			</tr>
			<tr valign="top">
		</tbody>
		</table>
		</div></div></div></div>
		<?php
	}

	function display_upgrade_from_free_button($subscription, $pricing, $user_id, $fromsub_id = false) {
		if($pricing[0]['amount'] == 0) {
			// a free first level
			$this->display_upgrade_button($subscription, $pricing, $user_id, $fromsub_id);
		} else {
			echo $this->build_subscribe_button($subscription, $pricing, $user_id, $fromsub_id);
		}

	}

	function display_upgrade_button($subscription, $pricing, $user_id, $fromsub_id = false) {
		echo $this->single_button($pricing, $subscription, $user_id, $subscription->sub_id(), $fromsub_id);
	}

	function display_cancel_button($subscription, $pricing, $user_id) {
		echo '<form class="unsubbutton" action="' . M_get_subscription_permalink() . '" method="post">';
		wp_nonce_field('cancel-sub_' . $subscription->sub_id());
		echo "<input type='hidden' name='action' value='unsubscribe' />";
		echo "<input type='hidden' name='gateway' value='" . $this->gateway . "' />";
		echo "<input type='hidden' name='subscription' value='" . $subscription->sub_id() . "' />";
		echo "<input type='hidden' name='user' value='" . $user_id . "' />";
		echo "<input type='submit' name='submit' value=' " . __('Unsubscribe', 'membership') . " ' class='button blue' />";
		echo "</form>";
	}


	function update() {
		update_option($this->gateway . '_api_key', $_POST[$this->gateway . '_api_key']);
		update_option($this->gateway . '_button', $_POST[$this->gateway . '_button']);
		update_option($this->gateway . '_notification_email', $_POST[$this->gateway . '_notification_email']);
		update_option($this->gateway . '_admin_email', $_POST[$this->gateway . '_admin_email']);
		$u = trailingslashit($_POST[$this->gateway . '_redirect_url']);
		if (strncasecmp($u, 'http', 4) != 0)
			$u = home_url($u);
		update_option($this->gateway . '_redirect_url', $u);
		update_option($this->gateway . '_pricing_currency', $_POST[$this->gateway . '_pricing_currency']);
		update_option($this->gateway . '_transaction_speed', $_POST[$this->gateway . '_transaction_speed']);
		update_option($this->gateway . '_full_notifications', $_POST[$this->gateway . '_full_notifications'] == 'checked' ? 1 : 0);
		update_option($this->gateway . '_testnet', $_POST[$this->gateway . '_testnet'] == 'checked' ? 1 : 0);
		update_option($this->gateway . '_debugfile', $_POST[$this->gateway . '_debugfile']);
		$this->set_bpOptions();
		// default action is to return true... we'd be kinda screwed, otherwise...
		return true;
	}

/*
	function build_posData($user_id, $sub_id, $amount, $sublevel = 0, $fromsub = 0) {
		global $M_options;

		$posData = time() . ':' . $user_id . ':' . $sub_id . ':';
		$key = md5('MEMBERSHIP' . apply_filters('membership_amount_' . $M_options['paymentcurrency'], $amount));

		if ($fromsub === false) {
			$fromsub = filter_input(INPUT_GET, 'from_subscription', FILTER_VALIDATE_INT);
		}

		$posData .= $key;
		$posData .= ":" . $sublevel . ":" . $fromsub;

		return $posData;
	}
*/
	// The original function above resulted in:
	// time:   user_id  :    sub_id  :md5 :  sublevel  :  fromsub
	// int : bigint(20) : bigint(20) : 16 : bigint(20) : bigint(20)
	//   8 1          8 1          8 1 16 1          8 1 8
        // = 61 bytes - 5 (:s) = 56 bytes
	// This needs to be only 37 bytes to fit BitPay's 100-character limit, including the hash, when JSON-encoded
	// We need to remove 19 bytes of least significance
	// Let's mask out some things
        // time: user_id % 0xffff : sub_id % 0xff : md5 : sublevel % 0xff : fromsub % 0xff
	// 8 : 2 : 1 : 16 : 1 : 1 = 29 + 5 for delims = 34 but only 29 needed
	// strlen(base64_encode('29 bytes')) =~ 39 ... no good
	// Let's do better:
	// 8 : 4 : 4 : 4 : 2 : 2 = 24 bytes
	// strlen(base64_encode('24 bytes')) =~ 32 ... OK!
	// NOTE: There's entirely too much bit-twiddling here. BitPay's artificial limit of 100 bytes is too low.
	function build_posData($user_id, $sub_id, $amount, $sublevel = 0, $fromsub = 0) {
		global $M_options;

		$time = time();
		// Stupid ol' pack() can't handle 64-bit integers, so...
		$t1 = ($time & 0xffffffff00000000) >> 32;
		$t2 = $time & 0xffffffff;
		$uid = $user_id & 0xffffffff;	// Up to 4 billion users. Ambition, comrades!
		$sid = $sub_id & 0xffffffff;	// up to 4 billion of these...
		// take the hash in binary string format, and take the last 4 bytes of it
		//echo 'build_posData: ' . 'MEMBERSHIP' . apply_filters('membership_amount_' . $M_options['paymentcurrency'], $amount);
		$key = md5('MEMBERSHIP' . apply_filters('membership_amount_' . $M_options['paymentcurrency'], $amount), true);
		$key = substr($key, -4);
		$sl = $sublevel & 0xffff;	// up to 65536 subscription levels
		if ($fromsub === false)
			$fromsub = filter_input(INPUT_GET, 'from_subscription', FILTER_VALIDATE_INT);
		$fs = $fromsub & 0xffff;	// same
		$posData = pack('NNNN', $t1, $t2, $uid, $sid) . $key . pack('nn', $sl, $fs);
		$posData = base64_encode($posData);
		return $posData;
	}

	function explode_posData($data) {
		self::dprint('explode_posData(' . $data . ')');
		///return explode(':', $data);	// le sigh...
		$data = base64_decode($data);
		$left = substr($data, 0, 16);
		$key = substr($data, 16, 4);
		$right = substr($data, 20);
		list($t1, $t2, $user_id, $sub_id) = array_values(unpack('N4', $left));
		$time = $t1 << 32 | $t2;
		list($sublevel, $fromsub) = array_values(unpack('n2', $right));
		return array($time, $user_id, $sub_id, $key, $sublevel, $fromsub);
	}

	function keymatch($key, $newkey_unhashed) {
		// This used to be straight comparison of MD5 hex hash strings:
	/*
		if ($key == md5($newkey))
			return true;
		return false;
	*/
		// Take the binary string representations of the 128-bit MD5 hash
		$nk = md5($newkey_unhashed, true);
		// Drop the first half
		$nk = substr($nk, -4);
		// return true if equal
		if ($key == $nk)
			return true;
		return false;
	}

	function email_plugin_or_site_admin($subj, $msg) {
		$email_address = get_option($this->gateway . '_admin_email', get_option('admin_email'));
		if (!$email_address || empty($email_address))
			return;
		wp_mail($email_address, $subj, self::safe_var_dump($msg));
	}

	function single_button($pricing, $subscription, $user_id, $sublevel = 0, $fromsub = 0) {
		global $M_options;
		$u = new WP_User($user_id);

		$this->set_bpOptions();
		if (empty($M_options['paymentcurrency']))
			$M_options['paymentcurrency'] = 'USD';

		$html = '';
		// TODO: We really should have an order ID...
		$orderId = '';
		$price = apply_filters('membership_amount_' . $M_options['paymentcurrency'], number_format($pricing[0]['amount'], 2, '.' , ''));
		$posData = $this->build_posData($user_id, $subscription->id, number_format($pricing[0]['amount'], 2, '.' , ''), $sublevel, $fromsub);
		$options = array('itemDesc' => $u->user_email . ': ' . $subscription->sub_name());
		$resp = bpCreateInvoice($orderId, $price, $posData, $options);
				
		if (!isset($resp) || empty($resp) || isset($resp['error']) || !isset($resp['exceptionStatus']) || $resp['exceptionStatus']) {
			$html .= __('There was an error generating an invoice at BitPay. Please wait a little while and try again.', 'membership');
			$m = 'single_button(';
			$m .= "\n\t" . '$pricing = ' . self::safe_var_dump($pricing);
			$m .= "\n\t" . '$subscription = ' . preg_replace('/(.dbpassword:protected. => )[^\n]*\n/m', '${1}(HIDDEN)' . "\n", self::safe_var_dump($subscription));
			$m .= "\n\t" . '$user_id = ' . self::safe_var_dump($user_id);
			$m .= "\n\t" . '$sublevel = ' . self::safe_var_dump($sublevel);
			$m .= "\n\t" . '$fromsub = ' . self::safe_var_dump($fromsub);
			$m .= "\n):\n\n";
			$m .= "bpCreateInvoice(\n";
			$m .= "\n\t" . '$orderId = ' . self::safe_var_dump($orderId);
			$m .= "\n\t" . '$price = ' . self::safe_var_dump($price);
			$m .= "\n\t" . '$posData = ' . self::safe_var_dump($posData);
			$m .= "\n\t" . '$options = ' . self::safe_var_dump($options);
			$m .= "\n" . ')';
			if (isset($resp) && !empty($resp))
				$m .= ' -> $resp =' . "\n" . self::safe_var_dump($resp);
			$this->email_plugin_or_site_admin(__('BitPay invoice generation failed'), $m);
			// early exit
			return $html;
		}
//... cut off last 3 digits from this time stamp, because we don't want milliseconds
		$resp['invoiceTime'] = substr( $resp['invoiceTime'], 0, -3 );

		//$this->_record_transaction($user_id, $sublevel, $price, get_option($this->gateway . '_pricing_currency'), $resp['invoiceTime'], $resp['id'], 'created', __('Invoice created', 'membership'));
		$html .= '<span class="btc-membership-price">';
		$html .= 'BTC ' . $resp['btcPrice'];
		$html .= '</span>';
		$html .= '<br /><br />';
		$button = get_option($this->gateway . "_button", '');
		$html .= '<span class="bitpay-bitton">';
		$html .= '<a href="' . $resp['url'] . '"><img src="' . $button . '" alt="BitPay" /></a>';
		$html .= '</span>';
		return $html;
	}

	function build_subscribe_button($subscription, $pricing, $user_id, $sublevel = 1, $fromsub = 0) {
		if (!empty($pricing)) {
			return $this->single_button($pricing, $subscription, $user_id, $sublevel, $fromsub);
		}
	}

	function display_subscribe_button($subscription, $pricing, $user_id, $sublevel = 1) {
		echo $this->build_subscribe_button($subscription, $pricing, $user_id, $sublevel);
	}

	function IPN_error_debug($code, $msg, $data = '', $email_admin = false) {
		status_header($code);
		echo $msg;
		self::dprint("$code\n$msg\n" . self::safe_var_dump($data));
		if ($email_admin)
			$this->email_plugin_or_site_admin('BitPay IPN error', "$code\n$msg\n" . self::safe_var_dump($data));
		exit;
	}

	// BitPay IPN handling code
	function handle_bitpay_return() {
		global $bpOptions;

		$this->set_bpOptions();
		// First, try to make sure this is really coming from BitPay
		$remote_ip = $this->_get_remote_ip();
		// TODO: header('403 Forbidden - not on whitelist');
/*
		$this->maybe_update_bitpay_ip_whitelist();
		$wl = get_option($this->gateway . '_ip_whitelist');
		if (!$wl)
			IPN_error_debug('403 Forbidden', 'BitPay IPN request with no whitelist in place!', '', true);
		if (!in_array($remote_ip, $wl['whitelist']))
			IPN_error_debug('403 Forbidden', 'BitPay IPN request from unauthorized IP address: ' . $remote_ip, file_get_contents('php://input'), true);
*/
		self::dprint('Received BitPay IPN from ' . $remote_ip);

		// Now make sure it's valid. bpVerifyNotification reads in form php://input and verifies the posData hash
		//$invoice = bpVerifyNotification(true);

		$post = file_get_contents("php://input");
		if (!$post)
			$this->IPN_error_debug(400, 'No post data', '', true);
		$invoice = json_decode($post, true);
		if (!is_array($invoice))
			$this->IPN_error_debug(400, 'Malformed JSON', $post, true);
		if (!array_key_exists('posData', $invoice))
			$this->IPN_error_debug(400, 'Missing posData', $invoice, true);
		$posData = $invoice['posData'];
		$j = json_decode($posData, true);
		if ($j != NULL) {
			$posData = $j['posData'];
			if ($bpOptions['verifyPos'] and $j['hash'] != bpHash(serialize($posData), $bpOptions['apiKey']))
				$this->IPN_error_debug(403, 'Invalid posData hash', $invoice, true);
		} elseif ($bpOptions['verifyPos'])
			$this->IPN_error_debug(500, '', 'verifyPos is true but no has from BitPay', true);
		if (isset($invoice['error'])) {
			$this->IPN_error_debug(200, '', "BitPay IPN with error code set\n$invoice", true);
		}

		self::dprint('New IPN: ' . self::safe_var_dump($invoice));
		$this->email_plugin_or_site_admin('New IPN', self::safe_var_dump($invoice) . "\n\n" . $post);

		// process BitPay response
		$new_status = false;
		$id = $invoice['id'];
		$amount = $invoice['price'];
		$currency = $invoice['currency'];
		list($timestamp, $user_id, $sub_id, $key, $sublevel, $fromsub) = $this->explode_posData($posData);
		$factory = Membership_Plugin::factory();
		$status = $invoice['status'];
		self::dprint('Unpacked IPN data:'
			. " \$id = $id"
			. " \$amount = $amount"
			. " \$currency = $currency"
			. " \$posData = $posData"
			. " \$timestamp = $timestamp"
			. " \$user_id = $user_id"
			. " \$sub_id = $sub_id"
			. " \$sublevel = $sublevel"
			. " \$sublevel = $fromsub"
			. " \$status = $status"
		);
		$member = $factory->get_member($user_id);
		switch ($status) {
			case 'new':
				// case: invoice created and acknowledged by BitPay
				$note = __('New invoice', 'membership');
				//$this->_record_transaction($user_id, $sub_id, $amount, $currency, $timestamp, $id, $status, $note);
				self::dprint('New BitPay invoice: ' . $id);
				//do_action('membership_payment_new', $user_id, $sub_id, $amount, $currency, $id);
				break;
			case 'paid':
			case 'confirmed':
			case 'complete':
				// case: successful payment
				if (!$this->keymatch($key, 'MEMBERSHIP' . $amount)) {
					self::dprint('Received key does not match ' . 'MEMBERSHIP' . $amount);
					if ($member) {
						if (defined('MEMBERSHIP_DEACTIVATE_USER_ON_CANCELATION') && MEMBERSHIP_DEACTIVATE_USER_ON_CANCELATION == true) {
							self::dprint('Deactivating member ' . $user_id);
							$member->deactivate();
						}
					}
				} elseif (!$this->_check_duplicate_transaction($user_id, $sub_id, $timestamp, trim($id))) {
					$this->_record_transaction($user_id, $sub_id, $amount, $currency, $timestamp, trim($id), $status, '');
					if ($member) {
						// This is the first level of a subscription so we need to create one if it doesn't already exist
						self::dprint('Creating subscription for user ID ' . $user_id);
						$member->create_subscription($sub_id, $this->gateway);
						do_action('membership_payment_subscr_signup', $user_id, $sub_id);
						// remove any current subs for upgrades
						$sub_ids = $member->get_subscription_ids();
						foreach ($sub_ids as $fromsub) {
							if ($sub_id == $fromsub)
								continue;
							self::dprint('Dropping subscription ' . $fromsub . ' for user ID ' . $user_id);
							$member->drop_subscription($fromsub);
						}
					}
					self::dprint('BitPay invoice marked ' . $status . ':' . $id);
				}
				break;
			case 'expired':
				// case: invoice expired without payment
				$note = __('Invoice expired without payment. No action required.', 'membership');
				$this->_record_transaction($user_id, $sub_id, $amount, $currency, $timestamp, $id, $status, $note);
				self::dprint('BitPay invoice expired: ' . $id);
				do_action('membership_payment_expired', $user_id, $sub_id, $amount, $currency, $id);
				break;
			case 'invalid':
				// case: payment was not confirmed within 1 hour
				$note = __('Invoice marked invalid (payment not confirmed within 1 hour).', 'membership');
				$this->_record_transaction($user_id, $sub_id, $amount, $currency, $timestamp, $id, $status, $note);
				self::dprint('BitPay invoice marked invalid: ' . $id . "\n" . self::safe_var_dump($invoice));
				// TODO: Email exception to admin
				$msg = "BitPay has marked its invoice number $id (included below) as invalid, meaning that payment was not confirmed by the Bitcoin network within 1 hour of receipt.\n";
				$msg .= "\n";
				$msg .= "This can be caused by three different rare events:\n";
				$msg .= "\t1: The Bitcoin blockchain has forked, and the payment was on the losing fork.\n";
				$msg .= "\t2: Less than 6 blocks were solved during the 1-hour confirmation period.\n";
				$msg .= "\t3: Something on BitPay's end is broken.\n";
				$msg .= "\n";
				$msg .= "You should contact BitPay to resolve this issue. Meanwhile, the member's entitlement in the system has not been affected.\n";
				$msg .= "\n";
				$msg .= "Member ID: $user_id\n";
				$msg .= "Invoice:\n";
				$msg .= self::safe_var_dump($invoice);
				if (get_option($this->gateway . '_notification_email'))
					wp_mail(get_option($this->gateway . '_notification_email'), 'IMPORTANT: BitPay invoice marked invalid', $msg);
				wp_mail(get_option($this->gateway . '_notification_email', get_option('admin_email')), 'IMPORTANT: BitPay invoice marked invalid', $msg);
				do_action('membership_payment_invalid', $user_id, $sub_id, $amount, $currency, $id);
				break;
			default:
				// case: I don't think we're in Kansas any more...
				self::dprint('BitPay IPN request with unknown status. Emailing admin. ');
				$this->email_plugin_or_site_admin('BitPay IPN error: unknown status', self::safe_var_dump($invoice));
		}
		status_header(200);
		// TODO: Check if this is really a good idea. Membership might still need to do things here.
		exit;
	}
}

Membership_Gateway::register_gateway('bitpay', 'bitpay');
