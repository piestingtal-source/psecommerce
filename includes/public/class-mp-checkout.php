<?php

// Beenden, wenn direkt darauf zugegriffen wird
if ( !defined( 'ABSPATH' ) ) exit;

class MP_Checkout {

	/**
	 * Bezieht sich auf eine einzelne Instanz der Klasse
	 *12.3.20 alles fine DN
	 * @since 1.0
	 * @access private
	 * @var object
	 */
	private static $_instance = null;

	/**
	 * Bezieht sich auf den aktuellen Checkout-Schritt
	 *
	 * @since 1.0
	 * @access protected
	 * @var string
	 */
	protected $_step = null;

	/**
	 * Bezieht sich auf die aktuelle Kassenschrittnummer
	 *
	 * @since 1.0
	 * @access protected
	 * @var int
	 */
	protected $_stepnum = 1;

	/**
	 * Bezieht sich auf die Kassenbereiche
	 *
	 * @since 1.0
	 * @access protected
	 * @var array
	 */
	protected $_sections = array();

	/**
	 * Bezieht sich auf die Checkout-Fehler
	 *
	 * @since 1.0
	 * @access protected
	 * @var array
	 */
	protected $_errors = array();

	/**
	 * Ruft die einzelne Instanz der Klasse ab
	 *
	 * @since 1.0
	 * @access public
	 * @return object
	 */
	public static function get_instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new MP_Checkout();
		}
		return self::$_instance;
	}

	/**
	 * Konstruktorfunktion
	 *
	 * @since 1.0
	 * @access private
	 */
	private function __construct() {
		/**
		 * Filtert das Array der Kassenbereiche
		 *
		 * @since 1.0
		 * @param array Das aktuelle Abschnitts-Array.
		 */
		$cart				 = mp_cart();
		$is_download_only	 = $cart->is_download_only();
		$this->_sections	 = apply_filters( 'mp_checkout/sections_array', array(
			'login-register'			 => __( 'Anmelden/Registrieren', 'mp' ),
			'billing-shipping-address'	 => ( !mp()->download_only_cart( mp_cart() ) ) ? __( 'Rechnungs-/Lieferadresse', 'mp' ) : __( 'Rechnungsinformationen', 'mp' ),
			'shipping'					 => __( 'Versandart', 'mp' ),
			'order-review-payment'		 => __( 'Bestellung/Zahlung überprüfen', 'mp' ),
		) );

		if ( !$this->_need_shipping_step() ) {
			unset( $this->_sections[ 'shipping' ] );
		}

		add_action( 'wp_enqueue_scripts', array( &$this, 'enqueue_scripts' ) );
		add_filter( 'mp_cart/after_cart_html', array( &$this, 'payment_form' ), 10, 3 );
		add_filter( 'mp_checkout/address_fields_array', array( &$this, 'contact_details_collection' ), 1, 2 );

		// Checkout-Daten aktualisieren
		add_action( 'wp_ajax_mp_update_checkout_data', array( &$this, 'ajax_update_checkout_data' ) );
		add_action( 'wp_ajax_nopriv_mp_update_checkout_data', array( &$this, 'ajax_update_checkout_data' ) );

		// Kasse verarbeiten
		add_action( 'wp_ajax_mp_process_checkout', array( &$this, 'ajax_process_checkout' ) );
		add_action( 'wp_ajax_nopriv_mp_process_checkout', array( &$this, 'ajax_process_checkout' ) );

		// Eventuell zur Kasse gehen bestätigen
		add_action( 'wp', array( &$this, 'maybe_process_checkout_confirm' ) );
		add_action( 'wp', array( &$this, 'maybe_process_checkout' ) );
	}

	/**
	 * Stelle fest, ob der Versandschritt erforderlich ist, wechsel auch zum ursprünglichen Blog zurück oder verursache seltsame Dinge
	 *
	 * @since 1.0
	 * @access protected
	 * @return bool
	 */
	protected function _need_shipping_step() {
		$blog_ids           = mp_cart()->get_blog_ids();
		$need_shipping_step = false;
		$current_blog_id    = get_current_blog_id();
		while ( 1 ) {
			if ( mp_cart()->is_global ) {
				$blog_id = array_shift( $blog_ids );
				mp_cart()->set_id( $blog_id );
			}

			if ( 'calculated' == mp_get_setting( 'shipping->method' ) ) {
				$need_shipping_step = true;
			}

			if ( ( mp_cart()->is_global && false === current( $blog_ids ) ) || ! mp_cart()->is_global ) {
				mp_cart()->reset_id();
				break;
			}
		}
		if ( mp_cart()->is_global ) {
			switch_to_blog( $current_blog_id );
		}

		return $need_shipping_step;
	}

	/**
	 * Versandabschnitt aktualisieren
	 *
	 * @since 1.0
	 * @access protected
	 */
	protected function _update_shipping_section() {
		$data = (array) mp_get_post_value( 'billing', array() );

		// Erzwinge das Leeren des Zustands, wenn er nicht vom Benutzer festgelegt wurde, um sicherzustellen, dass der alte Zustandswert gelöscht wird
		if( !isset( $data['state'] ) ) {
			$data['state'] = '';
		}

		foreach ( $data as $key => $value ) {
			$value = sanitize_text_field( trim( $value ) );
			mp_update_session_value( "mp_billing_info->{$key}", $value );
		}

		$enable_shipping_address = mp_get_post_value( 'enable_shipping_address' );
		mp_update_session_value( 'enable_shipping_address', $enable_shipping_address );


		if ( !$enable_shipping_address ) {
			mp_update_session_value( 'mp_shipping_info', mp_get_session_value( 'mp_billing_info' ) );
		}

	    //Versandinformationen speichern, auch wenn Versandinformationen zum Speichern anderer Felder deaktiviert sind (z. B. "special_instructions")
	    $data = (array) mp_get_post_value( 'shipping', array() );

	    if ( $data ) {
	        // Erzwinge das Leeren des Zustands, wenn er nicht vom Benutzer festgelegt wurde, um sicherzustellen, dass der alte Zustandswert gelöscht wird
	        if( $enable_shipping_address && !isset( $data['state'] ) ) {
                $data['state'] = '';
	        }

	        foreach ( $data as $key => $value ) {
                $value = trim( $value );
                mp_update_session_value( "mp_shipping_info->{$key}", $value );
	        }
	    }
	}

	/**
	 * Bestellprüfung/Zahlungsabschnitt aktualisieren
	 *
	 * @since 1.0
	 * @access protected
	 */
	protected function _update_order_review_payment_section() {
		$shipping_method = mp_get_setting( 'shipping->method' );
		$selected		 = (array) mp_get_post_value( 'shipping_method' );

		foreach ( $selected as $blog_id => $method ) {
			if ( 'calculated' == $shipping_method ) {

				if ( $method === false ) {
					return;
				}

				list( $shipping_option, $shipping_sub_option ) = explode( '->', $method );

				if ( mp_cart()->is_global ) {
					mp_update_session_value( "mp_shipping_info->shipping_option->{$blog_id}", $shipping_option );
					mp_update_session_value( "mp_shipping_info->shipping_sub_option->{$blog_id}", $shipping_sub_option );
				} else {
					mp_update_session_value( 'mp_shipping_info->shipping_option', $shipping_option );
					mp_update_session_value( 'mp_shipping_info->shipping_sub_option', $shipping_sub_option );
				}
			} else {
				if ( mp_cart()->is_global ) {
					mp_update_session_value( "mp_shipping_info->shipping_option->{$blog_id}", $shipping_method );
					mp_update_session_value( "mp_shipping_info->shipping_sub_option->{$blog_id}", '' );
				} else {
					mp_update_session_value( 'mp_shipping_info->shipping_option', $shipping_method );
					mp_update_session_value( 'mp_shipping_info->shipping_sub_option', '' );
				}
			}
		}
	}

	/**
	 * Felder für Adressnamen abrufen
	 *
	 * @since 1.2.7
	 * @access public
	 * @param string $type Entweder Rechnung oder Versand.
	 * @return array
	 */
	public function get_address_name_fields( $type ) {
		$address_fields = $this->get_address_fields( $type );
		$address_name_fields = array();
		foreach ( $address_fields as $field ) {
			if ( $field['type'] == 'complex' && !empty( $field['subfields'] ) && is_array( $field['subfields'] ) ) {
				foreach ( $field['subfields'] as $sfield ) {
					if ( !empty( $sfield['name'] ) ) {
						$address_name_fields[] = $sfield['name'];
					}
				}
			} elseif ( !empty( $field['name'] ) ) {
				$address_name_fields[] = $field['name'];
			}
		}

		return array_unique( $address_name_fields );
	}

	/**
	 * Adressfelder abrufen
	 *
	 * @since 1.2.7
	 * @access public
	 * @param string $type Entweder Rechnung oder Versand.
	 * @return array
	 */
	public function get_address_fields( $type ) {
		$country = mp_get_user_address_part( 'country', $type );

		// Country list
		if ( is_array( mp_get_setting( 'shipping->allowed_countries', '' ) ) ) {
			$allowed_countries = mp_get_setting( 'shipping->allowed_countries', '' );
		} else {
			$allowed_countries = explode( ',', mp_get_setting( 'shipping->allowed_countries', '' ) );
		}

		$all_countries		 = mp_countries();

		if ( mp_all_countries_allowed() ) {
			$allowed_countries	 = array_keys( $all_countries );
		}

		$countries = array();

		//$countries[''] = __('Wähle eins', 'mp');

		foreach ( $allowed_countries as $_country ) {
			$countries[ $_country ] = $all_countries[ $_country ];
		}

		// Bundesland/PLZ-Felder
		$state_zip_fields	 = array();
		$states				 = mp_get_states( $country );
		$state_zip_fields[]	 = array(
			'type'		 => 'select',
			'label'		 => __( 'Bundesland/Bezirk', 'mp' ),
			'name'		 => $this->field_name( 'state', $type ),
			'options'	 => $states,
			'hidden'	 => ( empty( $states ) ),
			'value'		 => mp_get_user_address_part( 'state', $type ),
			'atts'		 => array(
				'class' => 'mp_select2_search',
			),
			'validation' => array(
				'required' => true,
			),
		);

		$state_zip_fields[] = array(
			'type'		 => 'text',
			'label'		 => 'Postleitzahl',
			'name'		 => $this->field_name( 'zip', $type ),
			'value'		 => mp_get_user_address_part( 'zip', $type ),
			'hidden'	 => array_key_exists( $country, mp()->countries_no_postcode ),
			'validation' => array(
				'required' => true,
			),
			'atts'		 => array(
				'class' => 'mp_form_input',
			),
		);

		$address_fields = array(
			array(
				'type'		 => 'complex',
				'label'		 => __( 'Name', 'mp' ),
				'validation' => array(
					'required' => true,
				),
				'subfields'	 => array(
					array(
						'type'	 => 'text',
						'label'	 => __( 'Vorname', 'mp' ),
						'name'	 => $this->field_name( 'first_name', $type ),
						'value'	 => mp_get_user_address_part( 'first_name', $type ),
						'atts'	 => array(
							'class' => 'mp_form_input',
						),
					),
					array(
						'type'	 => 'text',
						'label'	 => __( 'Familienname', 'mp' ),
						'name'	 => $this->field_name( 'last_name', $type ),
						'value'	 => mp_get_user_address_part( 'last_name', $type ),
						'atts'	 => array(
							'class' => 'mp_form_input',
						),
					),
				),
			),
			array(
				'type'		 => 'text',
				'label'		 => __( 'Email Addresse', 'mp' ),
				'name'		 => $this->field_name( 'email', $type ),
				'value'		 => mp_get_user_address_part( 'email', $type ),
				'validation' => array(
					'required'	 => true,
					'email'		 => true,
				),
				'atts'		 => array(
					'class' => 'mp_form_input',
				),
			),
			array(
				'type'	 => 'text',
				'label'	 => __( 'Unternehmen (optional)', 'mp' ),
				'name'	 => $this->field_name( 'company_name', $type ),
				'value'	 => mp_get_user_address_part( 'company_name', $type ),
				'atts'	 => array(
					'class' => 'mp_form_input',
				),
			),
			array(
				'type'		 => 'text',
				'label'		 => __( 'Straße/Hausnummer', 'mp' ),
				'name'		 => $this->field_name( 'address1', $type ),
				'value'		 => mp_get_user_address_part( 'address1', $type ),
				'atts'		 => array(
					'placeholder'	 => __( 'Musterstraße 69', 'mp' ),
					'class'			 => 'mp_form_input',
				),
				'validation' => array(
					'required' => true,
				),
			),
			array(
				'type'	 => 'text',
				'label'	 => __( 'Adresszusatz (optional)', 'mp' ),
				'name'	 => $this->field_name( 'address2', $type ),
				'value'	 => mp_get_user_address_part( 'address2', $type ),
				'atts'	 => array(
					'placeholder'	 => __( '2. Etage, Tür 1', 'mp' ),
					'class'			 => 'mp_form_input',
				),
			),
			
			array(
				'type'		 => 'complex',
				'subfields'	 => $state_zip_fields,
			),

			array(
				'type'		 => 'text',
				'label'		 => __( 'Stadt/Ort', 'mp' ),
				'name'		 => $this->field_name( 'city', $type ),
				'value'		 => mp_get_user_address_part( 'city', $type ),
				'validation' => array(
					'required' => true,
				),
				'atts'		 => array(
					'class' => 'mp_form_input',
				),
			),

			array(
				'type'			 => 'select',
				'label'			 => __( 'Land', 'mp' ),
				'name'			 => $this->field_name( 'country', $type ),
				'options'		 => array_merge( array( '' => __( 'Auswählen', 'mp' ) ), $countries ), //array_merge(array('' => __('Wähle eins', 'mp')), $this->currencies),
				'value'			 => $country,
				'default_value'	 => '',
				'atts'			 => array(
					'class' => 'mp_select2_search',
				),
				'validation'	 => array(
					'required' => true,
				),
			),
			array(
				'type'	 => 'text',
				'label'	 => __( 'Telefon (optional)', 'mp' ),
				'name'	 => $this->field_name( 'phone', $type ),
				'value'	 => mp_get_user_address_part( 'phone', $type ),
				'atts'	 => array(
					'class' => 'mp_form_input',
				),
			),
		);

		/**
		 * Filter das Adressfeld-Array
		 *
		 * @since 1.0
		 * @param array $address_fields Die aktuellen Adressfelder.
		 * @param string $type Entweder Rechnung oder Versand.
		 */
		return (array) apply_filters( 'mp_checkout/address_fields_array', $address_fields, $type );
	}

	/**
	 * Adressfelder anzeigen
	 *
	 * @since 1.0
	 * @access public
	 * @param string $type Entweder Rechnung oder Versand.
	 * @param bool $value_only Optional, ob die Felder nur ihre Werte anzeigen sollen. Standardwert auf false.
	 * @return string
	 */
	public function address_fields( $type, $value_only = false ) {
		$address_fields = $this->get_address_fields( $type );

		$html = '';
		foreach ( $address_fields as $field ) {
			$field[ 'value_only' ] = $value_only;

			if ( $value_only ) {
				$field[ 'label' ] = false;
			}

			$html .= '<div class="mp_checkout_field"' . (( mp_arr_get_value( 'hidden', $field ) ) ? ' style="display:none"' : '') . '>' . $this->form_field( $field ) . '</div>';
		}

		/**
		 * Adressfeld html filtern
		 *
		 * @since 1.0
		 * @param string Der aktuelle HTML-Code.
		 * @param string Entweder Rechnung oder Versand.
		 */
		return apply_filters( 'mp_checkout/address_fields', $html, $type, $value_only );
	}

	/**
	 * Filter für Details-Sammlung als Nur Kontaktdaten in den digitalen Einstellungen festlegen
	 * Gutes Beispiel für die Anpassung von Adressfeldern auf der Checkout-Seite
	 *
	 * @since 1.2.7
	 * @access public
	 * @param array $address_fields Die aktuellen Adressfelder
	 * @param string $type Entweder Rechnung oder Versand.
	 * @return array
	 */
	public function contact_details_collection( $address_fields, $type ) {
		if ( mp()->download_only_cart( mp_cart() ) && mp_get_setting( 'details_collection' ) == "contact" ) {
			$allowed = array(
				'billing[first_name]',
				'billing[last_name]',
				'billing[email]',
				'billing[company_name]',
				'billing[phone]',
			);
			foreach ( $address_fields as $key => $field ) {
				if ( $field['type'] == 'complex' ) {
					foreach ( $field['subfields'] as $k => $sfield ) {
						if ( ! in_array( $sfield['name'], $allowed ) ) {
							unset( $address_fields[ $key ]['subfields'][ $k ] );
							if ( empty( $address_fields[ $key ]['subfields'] ) ) {
								unset( $address_fields[ $key ] );
							}
						}
					}
					continue;
				}
				if ( ! in_array( $field['name'], $allowed ) ) {
					unset( $address_fields[ $key ] );
				}
			}
		}

		return $address_fields;
	}

	/**
	 * Checkout-Fehler hinzufügen
	 *
	 * @since 1.0
	 * @access public
	 * @param string $msg Die Fehlermeldung.
	 * @param string $context Der Kontext der Fehlermeldung.
	 * @param bool $add_slashes Fügt Schrägstriche hinzu, um zu verhindern, dass doppelte Anführungszeichen Fehler verursachen.
	 */
	public function add_error( $msg, $context = 'general' , $add_slashes = true ) {
		if ( $add_slashes ){
			$msg = str_replace( '"', '\"', $msg ); //Verhindert dass doppelte Anführungszeichen Fehler verursachen.
		}


		if ( !isset( $this->_errors[ $context ] ) ) {
			$this->_errors[ $context ] = array();
		}

		$this->_errors[ $context ][] = $msg;
	}

	/**
	 * Checkout-Fehler erhalten
	 *
	 * @since 1.0
	 * @access public
	 * @param string $context Optional, der Fehlerkontext. Standardeinstellung auf "allgemein".
	 * @return array
	 */
	public function get_errors( $context = 'general' ) {
		$errors = mp_arr_get_value( $context, $this->_errors );

		/**
		 * Filtert die Fehlerzeichenfolge
		 *
		 * @since 1.0
		 * @param array $errors Das Fehlerarray.
		 * @param string $context Der Fehlerkontext.
		 */
		return (array) apply_filters( 'mp_checkout/get_errors', $errors, $context );
	}

	/**
	 * Kasse verarbeiten
	 *
	 * @since 1.0
	 * @access public
	 * @action wp_ajax_mp_process_checkout, wp_ajax_nopriv_mp_process_checkout
	 */
	public function ajax_process_checkout() {

		if ( $payment_method = mp_get_post_value( 'payment_method' ) ) {
			$cart			 = mp_cart();
			$billing_info	 = mp_get_user_address( 'billing' );
			$shipping_info	 = mp_get_user_address( 'shipping' );

			/**
			 * Für Gateways zum Einbinden und Verarbeiten von Zahlungen
			 *
			 * @since 1.0
			 * @param MP_Cart $cart Ein MP_Cart-Objekt.
			 * @param array $billing_info Eine Reihe von Zahlungsinformationen für Käufer.
			 * @param array $shipping_info Eine Reihe von Versandinformationen für Käufer.
			 */
			do_action( 'mp_process_payment_' . $payment_method, $cart, $billing_info, $shipping_info );

			if ( $this->has_errors() ) {
				// Es gibt Fehler - Kaution
				wp_send_json_error( array(
					'errors' => mp_arr_get_value( 'general', $this->_errors )
				) );
			}

			$order = wp_cache_get( 'order_object', 'mp' );
			wp_send_json_success( array( 'redirect_url' => $order->tracking_url( false ) ) );
		}

		wp_send_json_error( array(
			'errors' => array(
				'general' => __( 'Ein unbekannter Fehler ist aufgetreten. Bitte versuche es erneut.', 'mp' ),
			),
		) );
	}

	/**
	 * Versandabschnitt aktualisieren
	 *
	 * @since 1.0
	 * @access protected
	 */
	protected function _ajax_register_account() {

		if ( is_user_logged_in() ) {
			// Kaution - Benutzer ist eingeloggt (z.B. hat bereits ein Konto)
			return false;
		}

		$data = (array) mp_get_post_value( 'account', array() );

		$force_login 		      = mp_get_setting( 'force_login' );
		$enable_registration_form = mp_get_post_value( 'enable_registration_form' );
		$account_username 		  = mp_get_post_value( 'account_username' );
		$account_password 		  = mp_get_post_value( 'account_password' );
		$account_email 			  = mp_get_post_value( 'billing->email' );
		$first_name				  = mp_get_post_value( 'billing->first_name' );
		$last_name				  = mp_get_post_value( 'billing->last_name' );


		if ( wp_verify_nonce( mp_get_post_value( 'mp_create_account_nonce' ), 'mp_create_account' ) ) {

			if( ( $enable_registration_form || $force_login ) && $account_username && $account_password && $account_email  ) {
				$args = array(
					'user_login' => $account_username,
					'user_email' => $account_email,
					'user_pass'  => $account_password,
					'first_name' => $first_name,
					'last_name'  => $last_name,
					'role'       => 'subscriber'
				);

				$args = apply_filters( 'mp_register_user', $args );

				$user_id = wp_insert_user( $args );

				if ( ! is_wp_error( $user_id ) ) {
					add_action( 'set_logged_in_cookie', array($this, 'force_logged_in_cookie'), 5, 10 );
					$user_signon = wp_signon( array(
						'user_login'    => $account_username,
						'user_password' => $account_password,
						'remember'      => true,
					), false );


				}
			}
		}
	}

	public function force_logged_in_cookie( $logged_in_cookie, $expire, $expiration, $user_id, $scheme ){
		wp_set_current_user( $user_id ); // Erzwinge die Verwendung des aktuellen Benutzers in der Nonce-Generierung.
		$_COOKIE[LOGGED_IN_COOKIE] = $logged_in_cookie; // Setze das Cookie sofort nach der Ajax-Registrierung, um es in der Nonce-Generierung zu verwenden.
	}

	/**
	 * Checkout-Daten aktualisieren
	 *
	 * @since 1.0
	 * @access public
	 * @action wp_ajax_mp_update_checkout_data, wp_ajax_nopriv_mp_update_checkout_data
	 */
	public function ajax_update_checkout_data() {
		$this->_update_shipping_section();

		$error_messages = $this->_validate_checkout_data();
		if ( $error_messages ) {
			wp_send_json_error( array(
				'messages' => $error_messages,
				'count' => count( $error_messages ),
			) );
		}

		$this->_update_order_review_payment_section();
		$this->_ajax_register_account();

		$sections = array(
			'mp-checkout-section-shipping'				 => $this->section_shipping(),
			'mp-checkout-section-order-review-payment'	 => $this->section_order_review_payment(),
			'mp_checkout_nonce'							 => wp_nonce_field( 'mp_process_checkout', 'mp_checkout_nonce', false, false ),
		);

		wp_send_json_success( $sections );
	}


	/**
	 * Checkout-Daten validieren
	 *
	 * @return array Gibt ein assoziatives Array zurück, wobei Schlüssel - Name des Eingabefelds, Wert - Fehlermeldung
	 */
	private function _validate_checkout_data() {
		$messages = array();

		$types = array( 'billing' );
		if ( mp_get_post_value( 'enable_shipping_address' ) ) {
			$types[] = 'shipping';
		}
		foreach ( $types as $type ) {
			$address_name_fields = $this->get_address_name_fields( $type );
			$required_fields = array( 'first_name', 'last_name', 'email', 'address1', 'zip', 'city', 'country', 'policy' );

			/**
			* Filtere die erforderlichen Felder
			*
			* @since 1.2.7
			* @param array $required_fields Benötigte Felder.
			* @param string $type Entweder Rechnung oder Versand.
			*/
			$required_fields = apply_filters( 'mp_checkout/required_fields', $required_fields, $type );
			foreach ( $required_fields as $field ) {
				$name = "{$type}[{$field}]";
				$value = mp_get_user_address_part( $field, $type );
				if ( in_array( $name, $address_name_fields ) ) {
					switch ( $field ) {
						case 'zip':
							$country  = mp_get_user_address_part( 'country', $type );
							if ( !mp_is_valid_zip( $value, $country ) ) {
								$messages[ $name ] = __( 'Ungültige Postleitzahl', 'mp' );
							}
							break;

						case 'email':
							if ( empty( $value ) ) {
								$messages[ $name ] = __( 'Dieses Feld muss ausgefüllt sein!', 'mp' );
							} elseif ( !is_email( $value ) ) {
								$messages[ $name ] = __( 'Ungültige E-Mailadresse', 'mp' );
							}
							break;

						default:
							if ( empty( $value ) ) {
								$messages[ $name ] = __( 'Dieses Feld wird benötigt', 'mp' );
							}
							break;
					}
				}
			}
		}

		return apply_filters( 'mp_checkout/validation_messages', $messages, $type );
	}


	/**
	 * Kassenformular anzeigen
	 *
	 * @since 1.0
	 * @access public
	 * @param array $args {
	 * 		Optional, ein Array von Argumenten.
	 *
	 * 		@type bool $echo Ob echo oder return. Standardmäßig echo.
	 * }
	 */
	public function display( $args = array() ) {
		$args = array_replace_recursive( array(
			'echo' => true,
		), (array) $args );
		$this->_stepnum = 1;
		extract( $args );

		$disable_cart = mp_get_setting( 'disable_cart', 0 );

		if ( !mp_cart()->has_items() ) {
		if ( $disable_cart == '1' ) {
				return __( '<div class="mp_cart_empty"><h3 class="mp_sub_title">Oops!</h3><p class="mp_cart_empty_message">Der Warenkorb ist deaktiviert.</p></div><!-- end mp_cart_empty -->', 'mp' );
			} else {
				return sprintf( __( '<div class="mp_cart_empty"><h3 class="mp_sub_title">Hoppla!</h3><p class="mp_cart_empty_message">Sieht so aus, als hättest Du Deinem Einkaufswagen noch nichts hinzugefügt... <a href="%s">Gehen wir einkaufen!</a></p></div><!-- end mp_cart_empty -->', 'mp' ), mp_store_page_url( 'products', false ) );
			}

		}

		$html = '
			<!-- MP Checkout -->
			<section id="mp-checkout" class="mp_checkout">
			<noscript>' . __( 'Zum Auschecken ist Javascript erforderlich. Bitte aktiviere Javascript in Deinem Browser und aktualisiere diese Seite.', 'mp' ) . '</noscript>
			<form id="mp-checkout-form" class="mp_form' . (( get_query_var( 'mp_confirm_order_step' ) ) ? ' last-step' : '') . ' mp_form-checkout" method="post" style="display:none" novalidate>' .
		wp_nonce_field( 'mp_process_checkout', 'mp_checkout_nonce', true, false );

		/* Durchlaufe jeden Abschnitt, um festzustellen, ob ein bestimmter Abschnitt Fehler aufweist.
		  Wenn ja, lege diesen Abschnitt als aktuellen Abschnitt fest */
		$visible_section = null;
		foreach ( $this->_sections as $section => $heading_text ) {
			if ( ( $this->has_errors( $section ) ) ) {
				$visible_section = $section;
				break;
			}
		}

		foreach ( $this->_sections as $section => $heading_text ) {
			$method		 = 'section_' . str_replace( '-', '_', $section );
			$this->_step = $section;

			if ( method_exists( $this, $method ) ) {
				$tmp_html = $this->$method();

				if ( empty( $tmp_html ) ) {
					continue;
				}

				$id		 = 'mp-checkout-section-' . $section;
				$classes = array( 'mp_checkout_section', 'mp_checkout_section-' . $section );

				if ( !is_null( $visible_section ) ) {
					if ( $section == $visible_section ) {
						$classes[] = 'current';
					}
				} elseif ( get_query_var( 'mp_confirm_order_step' ) ) {
					if ( 'order-review-payment' == $section ) {
						$classes[] = 'current';
					}
				} elseif ( 1 === $this->_stepnum ) {
					$classes[] = 'current';
				}

				$html .= '
				<div id="' . $id . '" class="' . implode( ' ', $classes ) . '">';

				if ( !mp_doing_ajax( 'mp_update_checkout_data' ) ) {
					$link = ( get_query_var( 'mp_confirm_order_step' ) ) ? mp_store_page_url( 'checkout', false ) : 'javascript:;';
					$html .= $this->section_heading( $heading_text, $link, true );
				}

				$html .= '
					<div class="mp_checkout_section_errors' . (( $this->has_errors( $section ) ) ? ' show' : '') . '">' . $this->print_errors( $section, false ) . '</div><!-- end mp_checkout_section_errors -->
					<div class="mp_checkout_section_content">' . $tmp_html . '</div><!-- end mp_checkout_section_content -->
				</div>';

				$this->_stepnum ++;
			}
		}

		$html .= '
			</form>
			</section><!-- end mp-checkout -->';

		/**
		 * Filtere das Checkout-Formular html
		 *
		 * @since 1.0
		 * @param string $html Der aktuelle HTML-Code.
		 * @param array $this->_sections Eine Reihe von Abschnitten zum Anzeigen.
		 */
		$html = apply_filters( 'mp_checkout/display', $html, $this->_sections );

		if ( $echo ) {
			echo $html;
		} else {
			return $html;
		}
	}

	/**
	 * Skripte in die Warteschlange stellen
	 *
	 * @since 1.0
	 * @access public
	 */
	public function enqueue_scripts() {
		if ( !mp_is_shop_page( 'checkout' ) ) {
			return;
		}

		wp_register_script( 'jquery-validate', mp_plugin_url( 'ui/js/jquery.validate.min.js' ), array( 'jquery' ), MP_VERSION, true );
		wp_register_script( 'jquery-validate-methods', mp_plugin_url( 'ui/js/jquery.validate.methods.min.js' ), array( 'jquery', 'jquery-validate' ), MP_VERSION, true );
		wp_register_script( 'jquery-payment', mp_plugin_url( 'ui/js/jquery.payment.min.js' ), array( 'jquery' ), MP_VERSION, true );
		wp_enqueue_script( 'mp-checkout', mp_plugin_url( 'ui/js/mp-checkout.js' ), array( 'jquery-payment', 'jquery-validate-methods' ), MP_VERSION, true );

		wp_localize_script( 'mp-checkout', 'mp_checkout_i18n', array(
			'cc_num'		 => __( 'Bitte gib eine gültige Kreditkartennummer ein', 'mp' ),
			'cc_exp'		 => __( 'Bitte gib ein gültiges Kartenablaufdatum ein', 'mp' ),
			'cc_cvc'		 => __( 'Bitte gib einen gültigen Kartensicherheitscode ein', 'mp' ),
			'cc_fullname'	 => __( 'Bitte gib einen gültigen Vor- und Nachnamen ein', 'mp' ),
			'errors'		 => __( '<h4 class="mp_sub_title">Hoppla, wir haben %s entdeckt.</h4><p>Felder mit Fehlern werden unten in <span>rot</span> hervorgehoben. Wenn Du ein Feld eingibst, wird der tatsächlich aufgetretene Fehler angezeigt.</p>', 'mp' ),
			'error_plural'	 => __( 'Fehler', 'mp' ),
			'error_singular' => __( 'Fehler', 'mp' ),
		) );
	}

	/**
	 * Checkout-Feldnamen abrufen
	 *
	 * @since 1.0
	 * @access public
	 * @param string $name Der Feldname.
	 * @param string $prefix Optional, Zeichen zum Präfix vor dem Feldnamen.
	 */
	public function field_name( $name, $prefix = null ) {
		if ( !is_null( $prefix ) ) {
			return $prefix . '[' . $name . ']';
		}

		return $name;
	}

	/**
	 * Formularfeld-HTML basierend auf Werten aus einem Array erstellen
	 *
	 * @since 1.0
	 * @param array $field {
	 * 		An array of field properties
	 *
	 * 		@type string $type The type of field (e.g. text, password, textarea, etc)
	 * 		@type array $validation An array of validation rules. See http://jqueryvalidation.org/documentation/
	 * 		@type string $label The label of the form field
	 * 		@type string $name The name attribute of the field.
	 * 		@type array $atts An array of custom attributes.
	 * 		@type string $value The value of the field.
	 * 		@type array $subfields For complex fields, an array of subfields.
	 * 		@param array $options Required, if a select field.
	 * 		@param bool $value_only Whether the field should just display the value or the enter the field.
	 * }
	 * @return string
	 */
	public function form_field( $field ) {
		$atts		 = $html		 = $required	 = '';

		// Display label?
		if ( ($label = mp_arr_get_value( 'label', $field )) && 'checkbox' != mp_arr_get_value( 'type', $field, '' ) ) {
			$required = ( mp_arr_get_value( 'validation->required', $field ) ) ? ' <span class="mp_field_required">*</span>' : '';
			$html .= '
				<label class="mp_form_label">' . mp_arr_get_value( 'label', $field, '' ) . $required . '</label>';
		}

		// Convert validation arg into attributes
		foreach ( (array) mp_arr_get_value( 'validation', $field, array() ) as $key => $val ) {
			if ( is_bool( $val ) ) {
				$val = ( $val ) ? 'true' : 'false';
			}

			$val = mp_quote_it( $val );
			$atts .= " data-rule-{$key}={$val}";
		}

		// Get attributes
		$attributes = (array) mp_arr_get_value( 'atts', $field, array() );

		// Add ID attribute
		if ( false === mp_arr_get_value( 'id', $attributes ) ) {
			$attributes[ 'id' ] = 'mp-checkout-field-' . uniqid( true );
		}

		// Convert atts arg into attributes
		foreach ( $attributes as $key => $val ) {
			$val = mp_quote_it( $val );
			$atts .= " {$key}={$val}";
		}

		// Convert Counrty/State abbreviation when value_only
		if( mp_arr_get_value( 'value_only', $field ) && in_array( mp_arr_get_value( 'name', $field, '' ), array( 'billing[country]' , 'billing[state]', 'shipping[country]' , 'shipping[state]' ) ) && isset( $field['options'][$field['value']] ) ){
			$field['value'] = $field['options'][$field['value']];
		}

		switch ( mp_arr_get_value( 'type', $field, '' ) ) {
			case 'text' :
			case 'password' :
			case 'hidden' :
			case 'checkbox' :
				if ( mp_arr_get_value( 'value_only', $field ) ) {
					$html .= mp_arr_get_value( 'value', $field, '' );
				} else {
					$html .= '
					<input name="' . mp_arr_get_value( 'name', $field, '' ) . '" type="' . mp_arr_get_value( 'type', $field, '' ) . '" value="' . mp_arr_get_value( 'value', $field, '' ) . '"' . $atts . '>';

					if ( 'checkbox' == mp_arr_get_value( 'type', $field, '' ) ) {
						$html .= '<label class="mp_form_label" for="' . $attributes[ 'id' ] . '">' . mp_arr_get_value( 'label', $field, '' ) . $required . '</label>';
					}
				}
				break;

			case 'select' :
				if ( mp_arr_get_value( 'value_only', $field ) ) {
					$html .= mp_arr_get_value( 'value', $field );
				} else {
					$atts .= ' autocomplete="off"';
					$html .= '
					<select name="' . mp_arr_get_value( 'name', $field, '' ) . '" ' . $atts . '>';

					$options = (array) mp_arr_get_value( 'options', $field, array() );
					foreach ( $options as $value => $label ) {
						$html .= '
						<option value="' . esc_attr( $value ) . '" ' . selected( $value, mp_arr_get_value( 'value', $field ), false ) . '>' . esc_attr( $label ) . '</option>';
					}

					$html .= '
					</select>';
				}
				break;

			case 'complex' :
				$html .= '
				<div class="mp_checkout_fields">';

				foreach ( (array) mp_arr_get_value( 'subfields', $field, array() ) as $subfield ) {
					$subfield[ 'value_only' ] = mp_arr_get_value( 'value_only', $field );

					$top_label	 = true;
					if ( (($label		 = mp_arr_get_value( 'label', $subfield )) && mp_arr_get_value( 'label', $field )) || $subfield[ 'value_only' ] ) {
						$top_label = false;
						unset( $subfield[ 'label' ] );
					}

					if ( $validation = mp_arr_get_value( 'validation', $field ) ) {
						$subfield[ 'validation' ] = (array) $validation;
					}

					$html .= '
					<div class="mp_checkout_column mp_checkout_field"' . (( mp_arr_get_value( 'hidden', $subfield ) ) ? ' style="display:none"' : '') . '>' .
					$this->form_field( $subfield );

					if ( !$top_label && !$subfield[ 'value_only' ] ) {
						$html .= '
						<span class="mp_form_help-text">' . $label . '</span>';
					}

					$html .= '
					</div><!-- end mp_checkout_column/mp_checkout_field -->';
				}

				$html .= '
				</div><!-- end mp_checkout_fields -->';
				break;
		}

		return $html;
	}

	/**
	 * Get the previous/next step html link
	 *
	 * @since 1.0
	 * @access public
	 * @param string $what Either "prev" or "next".
	 * @return string
	 */
	public function step_link( $what, $section = false ) {
		$hash	 = $this->url_hash( $what );
		$text	 = '';
		$classes = array( 'mp_button', "mp_button-checkout-{$what}-step" );
		$link = false;

		switch ( $what ) {
			case 'prev' :
				if ( 1 === $this->_stepnum ) {
					break;
				}

				$text		 = __( '&laquo; Vorheriger Schritt', 'mp' );
				$classes[]	 = 'mp_button-secondary';
				$link = '<a class="' . implode( ' ', $classes ) . '" href="' . $hash . '">' . $text . '</a>';
				break;

			case 'next' :
				$text		 = __( 'Fortfahren &raquo;', 'mp' );
				$classes[]	 = 'mp_button-medium';
				$link = '<button class="' . implode( ' ', $classes ) . '" type="submit">' . $text . '</button>';
				break;
		}

		return apply_filters( 'mp_checkout_step_link', $link, $what, $section, $this->_stepnum );
	}

	/**
	 * Maybe process order
	 *
	 * @since 1.0
	 * @access public
	 * @action wp
	 */
	public function maybe_process_checkout() {
		if ( wp_verify_nonce( mp_get_post_value( 'mp_checkout_nonce' ), 'mp_process_checkout' ) ) {
			$payment_method	 = mp_get_post_value( 'payment_method' );
			$cart			 = mp_cart();
			$billing_info	 = mp_get_user_address( 'billing' );
			$shipping_info	 = mp_get_user_address( 'shipping' );

			// Save payment method to session
			mp_update_session_value( 'mp_payment_method', $payment_method );

			/**
			 * For gateways to tie into and process payment
			 *
			 * @since 1.0
			 * @param MP_Cart $cart An MP_Cart object.
			 * @param array $billing_info An array of buyer billing info.
			 * @param array $shipping_info An array of buyer shipping info.
			 */

			if ( apply_filters( 'mp_can_checkout', true, $this, $cart, $billing_info, $shipping_info ) == true ) {
				do_action( 'mp_process_payment_' . $payment_method, $cart, $billing_info, $shipping_info );
			}
		}
	}

	/**
	 * Maybe process order confirmation
	 *
	 * @since 1.0
	 * @access public
	 * @action wp
	 */
	public function maybe_process_checkout_confirm() {
		if ( get_query_var( 'mp_confirm_order_step' ) ) {
			/**
			 * For gateways to tie into before page loads
			 *
			 * @since 1.0
			 */
			do_action( 'mp_checkout/confirm_order/' . mp_get_session_value( 'mp_payment_method', '' ) );
		}
	}

	/**
	 * Print errors (if applicable)
	 *
	 * @since 1.0
	 * @access public
	 * @param string $context Optional, the error context. Defaults to "general".
	 * @param bool $echo Optional, whether to echo or return. Defaults to echo.
	 */
	public function print_errors( $context = 'general', $echo = true ) {
		$error_string = '';

		if ( $errors = $this->get_errors( $context ) ) {
			$error_string .= '
				<h4 class="mp_sub_title">' . __( 'Hoppla! Bei der Verarbeitung Deiner Zahlung ist ein Fehler aufgetreten.', 'mp' ) . '</h4>
				<ul>';

			foreach ( $errors as $error ) {
				$error_string .= $error;
			}

			$error_string .= '
				</ul>';
		}

		if ( $echo ) {
			echo $error_string;
		} else {
			return $error_string;
		}
	}

	/**
	 * Display payment form
	 *
	 * @since 1.0
	 * @access public
	 * @filter mp_cart/after_cart_html
	 */
	public function payment_form( $html, $cart, $display_args ) {
		if ( $cart->is_editable || $display_args[ 'view' ] == 'order-status' ) {
			// Cart isn't editable - bail
			return $html;
		}

		/**
		 * Filter the payment form heading text
		 *
		 * @since 1.0
		 * @param string
		 */
		$heading = '<h3 class="mp_sub_title">' . apply_filters( 'mp_checkout/payment_form/heading_text', __( 'Zahlung', 'mp' ) ) . '</h3>';

		$html .= '
			<div id="mp-checkout-payment-form">' .
		$heading . '
				<div id="mp-checkout-payment-form-errors"></div>';

		if ( get_query_var( 'mp_confirm_order_step' ) ) {
			/**
			 * For gateways to tie into and display payment confirmation info
			 *
			 * @since 1.0
			 */
			$form = apply_filters( 'mp_checkout/confirm_order_html/' . mp_get_session_value( 'mp_payment_method', '' ), '' );
		} else {
			/**
			 * For gateways to tie into and display payment forms
			 *
			 * @since 1.0
			 * @param string
			 */
			$form = apply_filters( 'mp_checkout_payment_form', '' );

			if ( empty( $form ) ) {
				$html .= wpautop( __( 'Es sind keine Gateways verfügbar, um diese Zahlung zu verarbeiten.', 'mp' ) );
			} else {
				$html .= '<div id="mp-payment-options-list">' . mp_list_payment_options( false ) . '</div><!-- end mp-payment-options-list -->';
			}
		}

		$html .= $form;
		$html .= '
			</div><!-- end mp-checkout-payment-form -->';

		return $html;
	}

	/**
	 * Check if there are any errors
	 *
	 * @since 1.0
	 * @access public
	 * @param string $context Optional, the context of the errors. Defaults to "general".
	 * @return bool
	 */
	public function has_errors( $context = 'general' ) {
		return ( mp_arr_get_value( $context, $this->_errors ) ) ? true : false;
	}

	/**
	 * Toggleable registration form on checkout
	 *
	 * @since 1.0
	 * @access public
	 * @return string
	 */
	public function register_toggle_form(  ) {

		if ( is_user_logged_in() ) {
			// Bail - user is logged in (e.g. already has an account)
			return false;
		}

		$required_fields = $required_labels = $html = '';

		if ( mp_get_setting( 'force_login' ) == true ) {
			$required_fields = 'data-rule-required="true" aria-required="true"';
			$required_labels = '<span class="mp_field_required">*</span>';
		}

		if ( mp_get_setting( 'force_login' ) == false ) {
			$html .= '
				<div class="mp_checkout_field mp_checkout_checkbox">
					<label class="mp_form_label"><input type="checkbox" class="mp_form_checkbox" name="enable_registration_form" value="1" autocomplete="off"> <span>' . __( 'Als Kunde registrieren?', 'mp' ) . '</span></label>
				</div><!-- end mp_checkout_field/mp_checkout_checkbox -->
			';
		}

		if ( mp_get_setting( 'force_login' ) == false ) {
			$html .= '
				<div id="mp-checkout-column-registration" style="display:none" class="mp_checkout_column_section">
					<h3 class="mp_sub_title">' . __( 'Account erstellen', 'mp' ) . '</h3>';
		} else {
			$html .= '
				<div id="mp-checkout-column-registration-needed" class="mp_checkout_column_section">
					<h3 class="mp_sub_title">' . __( 'Account registrieren', 'mp' ) . '</h3>';
		}

		$html .= '<div class="mp_checkout_field mp_checkout_column">
				<label class="mp_form_label">' . __( 'Benutzername', 'mp' ) . ' ' . $required_labels . '</label>
				<input type="text" name="account_username" id="mp_account_username" ' . $required_fields . ' data-rule-remote="' . esc_url( admin_url( 'admin-ajax.php?action=mp_check_if_username_exists' ) ) . '" data-msg-remote="' . __( 'Ein Konto mit diesem Benutzernamen ist bereits vorhanden', 'mp' ) . '"></input>
			  </div><!-- end mp_checkout_field -->';

		$html .= '<div class="mp_checkout_field mp_checkout_column">
				<label class="mp_form_label">' . __( 'Passwort', 'mp' ) . ' ' . $required_labels . '</label>
				<input type="password" name="account_password" ' . $required_fields . '></input>
			  </div><!-- end mp_checkout_field -->';
		$html .= wp_nonce_field( 'mp_create_account', 'mp_create_account_nonce' ) . '
			</div>';

		return $html;
	}

	/**
	 * Display the billing/shipping address section
	 *
	 * @since 1.0
	 * @access public
	 * @return string
	 */
	public function section_billing_shipping_address() {
		$shipping_addr			 = (array) mp_get_user_address( 'shipping' );
		$billing_addr			 = (array) mp_get_user_address( 'billing' );
		$enable_shipping_address = mp_get_session_value('enable_shipping_address');

		$html = '
				<div id="mp-checkout-column-billing-info" class="mp_checkout_column' . (( $enable_shipping_address ) ? '' : ' fullwidth') . '">
					<h3 class="mp_sub_title">' . __( 'Kunden Stammdaten', 'mp' ) . '</h3>' .
		$this->address_fields( 'billing' ) . '';

		$html .= '
			<div class="mp_checkout_field mp_checkout_checkbox mp_privacy_policy">
				<label class="mp_form_label">
					<input type="checkbox" class="mp_form_checkbox" data-rule-required="true" name="billing[policy]" value="1" autocomplete="off">
					<span class="mp_field_required">*</span>
					<span>' . __( "<strong>DSGVO</strong> Ich bestätige die weiterverarbeitung meiner persönlichen Daten zur Auftragsverarbeitung gemäß unserer Datenschutzrichtlinie.", "mp" ) . '&nbsp;'. get_the_privacy_policy_link() . '</span>
				</label>
				<label for="billing[policy]" class="error">' . __( "Dieses Feld wird benötigt.", "mp" ) . '</label>
			</div>
			
		';
		

		$cart				 = mp_cart();
		$is_download_only	 = $cart->is_download_only();

		if ( !mp()->download_only_cart( mp_cart() ) ) {
			$html .= '
					<div class="mp_checkout_field mp_checkout_checkbox">
						<label class="mp_form_label"><input type="checkbox" class="mp_form_checkbox" name="enable_shipping_address" value="1" autocomplete="off" ' . checked( true, $enable_shipping_address, false ) . '> <span>' . __( 'Lieferadresse anders als Rechnungsstellung?', 'mp' ) . '</span></label>
					</div><!-- end mp_checkout_field/mp_checkout_checkbox -->
				';

			$html .= '
				</div><!-- end mp-checkout-column-billing-info -->
					<div id="mp-checkout-column-shipping-info" class="mp_checkout_column fullwidth"' . (( $enable_shipping_address ) ? '' : ' style="display:none"') . '>
						<h3 class="mp_sub_title">' . __( 'Versand', 'mp' ) . '</h3>' .
			$this->address_fields( 'shipping' ) . '';
		}

		$html .= '
				</div><!-- end mp-checkout-column-shipping-info -->';

		// If has special instructions
		if ( mp_get_setting( 'special_instructions' ) == '1' ) {
			$html .= '<div id="mp-checkout-column-special-instructions" class="mp_checkout_column fullwidth"><div class="mp_checkout_field">
					<label class="mp_form_label">' . __( 'Spezielle Anweisungen', 'mp' ) . '</label>
				    <textarea name="shipping[special_instructions]">' . mp_get_user_address_part( 'special_instructions', 'shipping' ) . '</textarea>
				  </div><!-- end mp_checkout_field --></div><!-- end mp-checkout-column-special-instructions -->';
		}

		//Checkout registration form
		$html .= $this->register_toggle_form();

		$html .= '
			<div class="mp_checkout_buttons">' .
		$this->step_link( 'prev' ) .
		$this->step_link( 'next' ) . '
			</div><!-- end mp_checkout_buttons -->';

		return $html;
	}

	/**
	 * Display a section heading
	 *
	 * @since 1.0
	 * @access public
	 * @param string $text Heading text.
	 * @param string $link Optional, the link url for the heading.
	 * @param bool $step Optional, whether to show the current step num next to the heading text.
	 * @return string
	 */
	public function section_heading( $text, $link = false, $step = false ) {
		$html = '
			<h2 class="mp_checkout_section_heading">';

		if ( $step ) {
			$html .= '
				<span class="mp_checkout_step_num">' . $this->_stepnum . '</span>';
		}

		if ( false !== $link ) {
			$html .= '
				<a href="' . $link . '" class="mp_checkout_section_heading-link">' . $text . '</a>';
		} else {
			$html .= '
				<span class="mp_checkout_section_heading-link">' . $text . '</span>';
		}

		$html .= '
			</h2>';

		return $html;
	}

	/**
	 * Display the login/register section
	 *
	 * @since 1.0
	 * @access public
	 * @return string
	 */
	public function section_login_register() {
		$html = '';
		if ( !is_user_logged_in() && !MP_HIDE_LOGIN_OPTION ) {
			$html = wp_nonce_field( 'mp-login-nonce', 'mp_login_nonce', true, false ) . '
				<div class="mp_checkout_column">
					<h4 class="mp_sub_title">' . __( 'Hast Du einen Account?', 'mp' ) . '</h4>
					<p>' . __( 'Melde Dich an, um den Kaufvorgang zu beschleunigen.', 'mp' ) . '</p>
					<div class="mp_checkout_field">
						<label class="mp_form_label" for="mp-checkout-email">' . __( 'E-Mail Addresse/Benutzername', 'mp' ) . '</label>
						<input type="text" name="mp_login_email" class="mp_form_input">
					</div><!-- end mp_checkout_field -->
					<div class="mp_checkout_field">
						<label class="mp_form_label" for="mp-checkout-password">' . __( 'Passwort', 'mp' ) . '</label>
						<input type="password" name="mp_login_password" class="mp_form_input">
					</div><!-- end mp_checkout_field -->
					<button id="mp-button-checkout-login" type="button" class="mp_button mp_button-medium mp_button-checkout-login">' . __( 'Anmelden', 'mp' ) . '</button>
                                        <p><a href="' . wp_lostpassword_url( get_permalink() ) . '" title="Passwort vergessen">Passwort vergessen</a>
				</div><!-- end mp_checkout_column -->
				';
			if ( mp_get_setting( 'force_login' ) == false && ! is_user_logged_in() ) {
				$html .= '<div class="mp_checkout_column">
					<h4 class="mp_sub_title">' . __( 'Dein erster Enkauf hier?', 'mp' ) . '</h4>
					<p>' . __( 'Fahre mit dem Auschecken fort und am Ende hast Du die Möglichkeit, ein Konto zu erstellen.', 'mp' ) . '</p>
					<p><button type="button" class="mp_button mp_button-medium mp_button-checkout-next-step mp_continue_as_guest">' . __( 'Weiter als Gast', 'mp' ) . '</button></p>
				</div><!-- end mp_checkout_column -->';
			} else {
				$html .= '<div class="mp_checkout_column">
					<h4 class="mp_sub_title">' . __( 'Dein erster Einkauf?', 'mp' ) . '</h4>
					<p>' . __( 'Fahre mit dem Auschecken fort und erstelle am Ende ein Konto.', 'mp' ) . '</p>
					<p><button type="button" class="mp_button mp_button-medium mp_button-checkout-next-step mp_continue_as_guest">' . __( 'Weiter', 'mp' ) . '</button></p>
				</div><!-- end mp_checkout_column -->';
			}
		}
		/**
		 * Filter the section login html
		 *
		 * @since 1.0
		 * @param string The current html.
		 */
		return apply_filters( 'mp_checkout/section_login', $html );
	}

	/**
	 * Display the order review section
	 *
	 * @since 1.0
	 * @access public
	 * @return string
	 */
	public function section_order_review_payment() {

		$cart				 = mp_cart();
		$is_download_only	 = $cart->is_download_only();

		$html = '
			<div class="mp_checkout_column">
				<h3 class="mp_sub_title">' . __( 'Rechnungsadresse', 'mp' ) . '</h3>' .
		$this->address_fields( 'billing', true ) . '
			</div><!-- end mp_checkout_column -->';

		if ( !mp()->download_only_cart( mp_cart() ) ) {
			$html .= '
				<div class="mp_checkout_column">
					<h3 class="mp_sub_title">' . __( 'Lieferanschrift', 'mp' ) . '</h3>' .
			$this->address_fields( 'shipping', true ) . '
				</div><!-- end mp_checkout_column -->';
		}

		$html .= '
			<h3 class="mp_sub_title">' . __( 'Warenkorb', 'mp' ) . '</h3>' .
		mp_cart()->display( array(
			'editable' => false
		) );

		/**
		 * Filter the section payment html
		 *
		 * @since 1.0
		 * @param string The current html.
		 */
		return apply_filters( 'mp_checkout/order_review', $html );
	}

	/**
	 * Display the shipping section
	 *
	 * @since 1.0
	 * @access public
	 */
	public function section_shipping() {

		if ( mp_cart()->is_download_only() || 0 == mp_cart()->shipping_weight() ) {
			return false;
		}

		$blog_ids	 = mp_cart()->get_blog_ids();
		$html		 = '';

		while ( 1 ) {

			if ( mp_cart()->is_global ) {
				$force	 = true;
				$blog_id = array_shift( $blog_ids );
				mp_cart()->set_id( $blog_id );
				MP_Shipping_API::load_active_plugins( true );
			}

			$active_plugins	 = MP_Shipping_API::get_active_plugins();
			$shipping_method = mp_get_setting( 'shipping->method' );

			if ( 'calculated' == $shipping_method ) {
				if ( mp_cart()->is_global ) {
					$html .= '
						<h3>' . get_option( 'blogname' ) . '</h3>';
				}

				foreach ( $active_plugins as $plugin ) {
					$html .= '
						<div class="mp_shipping_method">
							<h4>' . $plugin->public_name . '</h4>';

					$html .= mp_list_plugin_shipping_options( $plugin );
					$html .= '
						</div><!-- end mp_shipping_method -->';
				}
			}

			if ( (mp_cart()->is_global && false === current( $blog_ids )) || !mp_cart()->is_global ) {
				mp_cart()->reset_id();
				break;
			}
		}

		$html .= '
						<div class="mp_checkout_buttons">' .
		$this->step_link( 'prev', 'shipping' ) .
		$this->step_link( 'next', 'shipping' ) . '
						</div><!-- end mp_checkout_buttons -->';


		/**
		 * Filter the shipping section html
		 *
		 * @since 1.0
		 * @param string $html The current html.
		 * @param string $shipping_method The selected shipping method per settings (e.g. calculated, flat-rate, etc)
		 * @param array $active_plugins The currently active shipping plugins.
		 */
		return apply_filters( 'mp_checkout/section_shipping', $html, $shipping_method, $active_plugins );
	}

	/**
	 * Get current/next url hash
	 *
	 * @since 1.0
	 * @access public
	 * @param string $what Either "prev" or "next".
	 * @return string
	 */
	public function url_hash( $what ) {
		$key = array_search( $this->_step, $this->_sections );

		switch ( $what ) {
			case 'next' :
				$slug = mp_arr_get_value( ($key + 1 ), $this->_sections, '' );
				break;

			case 'prev' :
				$slug = mp_arr_get_value( ($key - 1 ), $this->_sections, '' );
				break;
		}

		return '#' . $slug;
	}

	/**
	 * Returns the js needed to record ecommerce transactions.
	 *
	 * @since 1.0
	 * @access public
	 * @return string
	 */

	public function create_ga_ecommerce( $order_id,  $echo = false ) {
		$order = new MP_Order( $order_id );
		//if order not exist, just return false
		if ( $order->exists() == false ) {
			return false;
		}

		//so that certain products can be excluded from tracking
		$order = apply_filters( 'mp_ga_ecommerce', $order );

		$cart = $order->get_meta( 'mp_cart_info' );

		$products = $cart->get_items_as_objects();

		if ( mp_get_setting( 'ga_ecommerce' ) == 'old' ) {

			$js = '<script type="text/javascript">
try{
 pageTracker._addTrans(
		"' . esc_js( $order->post_title ) . '",							 // order ID - required
		"' . esc_js( get_bloginfo( 'blogname' ) ) . '",					 // affiliation or store name
		"' . $order->get_meta( 'mp_order_total' ) . '",								 // total - required
		"' . $order->get_meta( 'mp_tax_total' ) . '",									 // tax
		"' . $order->get_meta( 'mp_shipping_total' ) . '",							 // shipping
		"' . esc_js( $order->get_meta( 'mp_shipping_info->city' ) ) . '",		// city
		"' . esc_js( $order->get_meta( 'mp_shipping_info->state' ) ) . '",		 // state or province
		"' . esc_js( $order->get_meta( 'mp_shipping_info->country' ) ) . '"	 // country
	);';

			foreach ( $products as $product ) {
				$product = new MP_Product( $product->ID );
				$meta = $product->get_meta( 'sku' );
				$sku = !empty( $meta ) ? esc_attr( $product->get_meta( 'sku' ) ) : $product->ID;
						$js .= 'pageTracker._addItem(
				"' . esc_attr( $order->post_title ) . '", // order ID - necessary to associate item with transaction
				"' . $sku . '",									 // SKU/code - required
				"' . esc_attr( $product->title( false ) ) . '",			// product name
				"' . $product->get_price( 'lowest' ) . '",						// unit price - required
				"' . $cart->get_item_qty( $product->ID ) . '"					 // quantity - required
			);';
			}
			$js .= 'pageTracker._trackTrans(); //submits transaction to the Analytics servers
} catch(err) {}
</script>
';
		} else if ( mp_get_setting( 'ga_ecommerce' ) == 'new' ) {

			$js = '<script type="text/javascript">
	_gaq.push(["_addTrans",
		"' . esc_attr( $order->post_title ) . '",						 // order ID - required
		"' . esc_attr( get_bloginfo( 'blogname' ) ) . '",				 // affiliation or store name
		"' . $order->get_meta( 'mp_order_total' ) . '",								 // total - required
		"' . $order->get_meta( 'mp_tax_total' ) . '",									 // tax
		"' . $order->get_meta( 'mp_shipping_total' ) . '",							 // shipping
		"' . esc_attr( $order->get_meta( 'mp_shipping_info->city' ) ) . '",		// city
		"' . esc_attr( $order->get_meta( 'mp_shipping_info->state' ) ) . '",		 // state or province
		"' . esc_attr( $order->get_meta( 'mp_shipping_info->country' ) ) . '"	 // country
	]);';

			foreach ( $products as $product ) {
				$product = new MP_Product( $product->ID );
				$meta = $product->get_meta( 'sku' );
				$sku = !empty( $meta ) ? esc_attr( $product->get_meta( 'sku' ) ) : $product->ID;
						$js .= '_gaq.push(["_addItem",
				"' . esc_attr( $order->post_title ) . '", // order ID - necessary to associate item with transaction
				"' . $sku . '",									 // SKU/code - required
				"' . esc_attr( $product->title( false ) ) . '",			// product name
				"",												// category
				"' . $product->get_price( 'lowest' ) . '",						// unit price - required
				"' . $cart->get_item_qty( $product->ID ) . '"					 // quantity - required
			]);';
			}
			$js .= '_gaq.push(["_trackTrans"]);
</script>
';

			//add info for subblog if our GA plugin is installed
			if ( class_exists( 'Google_Analytics_Async' ) ) {

				$js = '<script type="text/javascript">
		_gaq.push(["b._addTrans",
			"' . esc_attr( $order->post_title ) . '",							 // order ID - required
			"' . esc_attr( get_bloginfo( 'blogname' ) ) . '",					 // affiliation or store name
			"' . $order->get_meta( 'mp_order_total' ) . '",									 // total - required
			"' . $order->get_meta( 'mp_tax_total' ) . '",									 // tax
			"' . $order->get_meta( 'mp_shipping_total' ) . '",								 // shipping
			"' . esc_attr( $order->get_meta( 'mp_shipping_info->city' ) ) . '",		// city
			"' . esc_attr( $order->get_meta( 'mp_shipping_info->state' ) ) . '",		 // state or province
			"' . esc_attr( $order->get_meta( 'mp_shipping_info->country' ) ) . '"	 // country
		]);';

				foreach ( $products as $product ) {
					$product = new MP_Product( $product->ID );
					$meta = $product->get_meta( 'sku' );
					$sku = !empty( $meta ) ? esc_attr( $product->get_meta( 'sku' ) ) : $product->ID;
							$js .= '_gaq.push(["b._addItem",
					"' . esc_attr( $order->post_title ) . '", // order ID - necessary to associate item with transaction
					"' . $sku . '",									 // SKU/code - required
					"' . esc_attr( $product->title( false ) ) . '",			// product name
					"",												// category
					"' . $product->get_price( 'lowest' ) . '",						// unit price - required
					"' . $cart->get_item_qty( $product->ID ) . '"					 // quantity - required
				]);';
				}
				$js .= '_gaq.push(["b._trackTrans"]);
	</script>
	';
			}
		} else if ( mp_get_setting( 'ga_ecommerce' ) == 'universal' ) {
			// add the UA code

			$js = '<script type="text/javascript">
		ga("require", "ecommerce", "ecommerce.js");
		ga("ecommerce:addTransaction", {
				"id": "' . esc_attr( $order->post_title ) . '",						// Transaction ID. Required.
				"affiliation": "' . esc_attr( get_bloginfo( 'blogname' ) ) . '",	// Affiliation or store name.
				"revenue": "' . $order->get_meta( 'mp_order_total' ) . '",						// Grand Total.
				"shipping": "' . $order->get_meta( 'mp_shipping_total' ) . '",					// Shipping.
				"tax": "' . $order->get_meta( 'mp_tax_total' ) . '"							 		// Tax.
			});';
			//loop the items

			foreach ( $products as $product ) {
				$product = new MP_Product( $product->ID );

				$meta = $product->get_meta( 'sku' );
				$sku = !empty( $meta ) ? esc_attr( $product->get_meta( 'sku' ) ) : $product->ID;
				$js .= 'ga("ecommerce:addItem", {
					 "id": "' . esc_attr( $order->post_title ) . '", // Transaction ID. Required.
					 "name": "' . esc_attr( $product->title( false ) ) . '",	 // Product name. Required.
					 "sku": "' . $sku . '",								// SKU/code.
					 "category": "",			 					// Category or variation.
					 "price": "' . $product->get_price( 'lowest' ) . '",				 // Unit price.
					 "quantity": "' . $cart->get_item_qty( $product->ID ) . '"		 // Quantity.
				});';
			}

			$js .='ga("ecommerce:send");</script>';
		}

		//echo or return
		if ( $echo && isset( $js ) ) {
			echo $js;
		}
		elseif ( isset( $js ) ) {
			return $js;
		}
	}

}

MP_Checkout::get_instance();