<?php

/**
 * Plugin Name: Slash Auth
 * Description: Log into a wordpress website via your slashtag
 * Version: 1.0.0
 * Author: Super Testnet
 */

function slashauth_makePassword() {
  $num1 = rand( 100000000, 999999999 );
  $num2 = rand( 100000000, 999999999 );
  $num3 = rand( 100000000, 999999999 );
  $num4 = rand( 100000000, 999999999 );
  $num5 = rand( 100000000, 999999999 );
  $num6 = rand( 100000000, 999999999 );
  $num7 = rand( 100000000, 999999999 );
  $num8 = rand( 100000000, 999999999 );
  $num = $num1 . $num2 . $num3 . $num4 . $num5 . $num6 . $num7 . $num8;
  $hex = hash( "sha256", $num );
  return $hex;
}

function slashauth_loginUser( $id ) {
  $user = get_user_by( 'id', $id );
  $creds = array(
    'user_login'    => $user->user_login,
    'user_password' => $user->user_pass,
    'remember'      => true
  );
  do_action( 'wp_login', $user->user_login, $user );
  $secure_cookie = is_ssl();
  $secure_cookie = apply_filters( 'secure_signon_cookie', $secure_cookie, $creds );
  global $auth_secure_cookie;
  $auth_secure_cookie = $secure_cookie;
  add_filter( 'authenticate', 'wp_authenticate_cookie', 30, 3 );
  if ( is_wp_error( $user ) ) {
    return $user;
  }
  wp_set_auth_cookie( $user->ID, $creds[ 'remember' ], $secure_cookie );
}

function slashauth_checkMeta( $slashtag ) {
  //find the user with the given slashtag
  $users = get_users(array(
    'meta_key' => 'slashtag',
    'meta_value' => $slashtag
  ));
  //if there is a user with this slashtag, then the array "users" will not be empty,
  //in fact it will have exactly one element: the user with that slashtag. So get
  //that user's user id and return it. Otherwise, return nothing, because that means
  //there is no user with that slashtag
  if ( !empty( $users ) ) {
    $user = $users[ 0 ];
    $user_id = $user->ID;
    return $user_id;
  }
  return;
}

add_action( 'wp_enqueue_scripts', 'slashauth_load_my_scripts' );

function slashauth_load_my_scripts( $hook ) {
    $my_js_ver  = date( "ymd-Gis", filemtime( plugin_dir_path( __FILE__ ) . 'js/qrcode.js' ) );
    wp_enqueue_script( 'qrcode', plugins_url( 'js/qrcode.js', __FILE__ ), array(), $my_js_ver );
}

function slashauth_makeAccountOrLogin( $clientid, $slashtag, $display_name, $user_bio, $slashauth_base64_avatar ) {
  if ( !$clientid || !$slashtag ) {
    die();
  }
  if ( slashauth_checkMeta( $slashtag ) ) {
    //if the user already has an account, log them in
    $user_id = slashauth_checkMeta( $slashtag );
    update_user_meta( $user_id, 'nickname', $display_name );
    if ( $user_bio ) {
      update_user_meta( $user_id, 'description', $user_bio );
    }
    if ( $slashauth_base64_avatar ) {
      //if the user's image changed, save their new image
      $prev_image = get_user_meta( $id_or_email, 'slashauth_base64_avatar', true );
      if ( $prev_image != $user_image ) {
        update_user_meta( $user_id, 'slashauth_base64_avatar', $slashauth_base64_avatar );
        update_user_meta( $user_id, 'slashauth_avatar_url', slashauth_addImage( $slashauth_base64_avatar ) );
      }
    }
    wp_update_user( array ( 'ID' => $user_id, 'display_name' => $display_name ) );    
    slashauth_loginUser( $user_id );
  } else {
    //otherwise create their account and log them in
    $password = slashauth_makePassword();
    $userdata[ "user_login" ] = substr( $slashtag, 6, 10 );
    $userdata[ "user_pass" ] = $password;
    $userdata[ "user_nicename" ] = substr( $slashtag, 6, 10 );
    $userdata[ "show_admin_bar_front" ] = false;
    $user_id = wp_insert_user( $userdata );
    $user_id_role = new WP_User( $user_id );
    update_user_meta( $user_id, 'slashtag', $slashtag );
    update_user_meta( $user_id, 'nickname', $display_name );
    if ( $user_bio ) {
      update_user_meta( $user_id, 'description', $user_bio );
    }
    if ( $slashauth_base64_avatar ) {
      update_user_meta( $user_id, 'slashauth_base64_avatar', $slashauth_base64_avatar );
      update_user_meta( $user_id, 'slashauth_avatar_url', slashauth_addImage( $slashauth_base64_avatar ) );
    }
    wp_update_user( array ( 'ID' => $user_id, 'display_name' => $display_name ) );
    slashauth_loginUser( $user_id );
  }
  echo 1;
  die();
}

function slashauth_getData( $url ) {
  ob_start();
  $ch = curl_init();
  curl_setopt( $ch, CURLOPT_URL, $url );
  $head = curl_exec( $ch );
  $httpCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
  curl_close( $ch );
  $mydata = ob_get_clean();
  return $mydata;
}

function slashauth_makeClientID() {
  $hex = slashauth_makePassword();
  $hex = substr( $hex, 0, 16 );
  return $hex;
}

function slashauth_checkStatus( $clientid ) {
  $data = slashauth_getData( "http://localhost:10249?clientid=$clientid" );
  return $data;
}

function slashauth_checkStatusAPI() {
  $clientid = $_GET[ "clientid" ];
  $status = slashauth_checkStatus( $clientid );
  if ( substr( $status, 0, 10 ) == "slash-auth" ) {
    echo $status;
  } else {
    $thisuser = json_decode( $status );
    $slashtag = $thisuser->slashtag;
    $display_name = $thisuser->name;
    if ( $thisuser->bio ) {
      $user_bio = $thisuser->bio;
    } else {
      $user_bio = null;
    }
    if ( $thisuser->image ) {
      $slashauth_base64_avatar = $thisuser->image;
    } else {
      $slashauth_base64_avatar = null;
    }
    echo slashauth_makeAccountOrLogin( $clientid, $slashtag, $display_name, $user_bio, $slashauth_base64_avatar );
  }
  die();
}

add_action( 'wp_ajax_slashauth_checkstatus', 'slashauth_checkStatusAPI' );
add_action( 'wp_ajax_nopriv_slashauth_checkstatus', 'slashauth_checkStatusAPI' );

function slashauth_showQR( $atts = array() ) {
  $clientid = slashauth_makeClientID();
  $data = slashauth_checkStatus( $clientid );
  $slashauth_qr_style = get_option( 'slashauth_qr_style' );
  $slashauth_caption_style = get_option( 'slashauth_caption_style' );
  $slashauth_copier_style = get_option( 'slashauth_copier_style' );
  $slashauth_caption_container_style = get_option( 'slashauth_caption_container_style' );
  $returnable = "";
  $returnable .= '<div id="slashauth-auth-qr" style="' . get_option( 'slashauth_container_style' ) . '"><a id="slashauth-auth-link" target="_blank"></a></div>';
  $returnable .= <<<XML
    <script>
      function copyText( element ) {
        element.select();
        element.setSelectionRange( 0, 99999 );
        navigator.clipboard.writeText( element.value );
        alert( 'copied ' + element.value );
      }
      function createQR( data ) {
        var dataUriPngImage = document.createElement( "img" ),
        s = QRCode.generatePNG( data, {
          ecclevel: "M",
          format: "html",
          fillcolor: "#FFFFFF",
          textcolor: "#373737",
          margin: 4,
          modulesize: 8
        });
        dataUriPngImage.src = s;
        dataUriPngImage.id = "slashauth-auth-image";
        dataUriPngImage.style = "$slashauth_qr_style";
        return dataUriPngImage;
      }
      document.getElementById( "slashauth-auth-link" ).appendChild( createQR( "$data".toUpperCase() ) );
      document.getElementById( "slashauth-auth-link" ).href = "$data";
      var captioncont = document.createElement( "center" );
      captioncont.id = "slashauth_caption_container";
      captioncont.style = "$slashauth_caption_container_style"
      var caption = document.createElement( "input" );
      caption.disabled = "true";
      caption.id = "slashauth-auth-caption";
      caption.style = "$slashauth_caption_style";
      caption.value = "$data";
      var copy_button = document.createElement( "button" );
      copy_button.innerText = "Copy";
      copy_button.style = "$slashauth_copier_style";
      copy_button.onclick = function() {
        copyText( document.getElementById( "slashauth-auth-caption" ) );
      }
      captioncont.append( caption );
      captioncont.append( copy_button );
      document.getElementById( "slashauth-auth-qr" ).append( captioncont );
      function getData( url ) {
        return new Promise( function( resolve, reject ) {
          var xhttp = new XMLHttpRequest();
          xhttp.onreadystatechange = function() {
            if ( this.readyState == 4 && ( this.status >= 200 && this.status < 300 ) ) {
              resolve( xhttp.responseText );
            };
          }
          xhttp.open( "GET", url, true );
          xhttp.send();
    	  });
      }
      var url = window.location.protocol + "//" +
      window.location.hostname +
      "/wp-admin/admin-ajax.php?action=slashauth_checkstatus&clientid=" +
      "$clientid";
      url = url.replace( /\#038\;/g, "" );
      async function getStatus( url ) {
        var status = await getData( url );
        console.log( "status:", status );
        if ( status.startsWith( "slash-auth" ) ) {
          setTimeout( function() {getStatus( url )}, 2000 );
        } else if ( status == 1 ) {
          window.location.href = window.location.protocol + "//" + window.location.hostname;
        }
      }
      getStatus( url );
    </script>
  XML;
  return $returnable;
}

add_shortcode( 'slashauth', 'slashauth_showQR' );

function slashauth_addImage( $base64 ) {

  $filename = slashauth_makePassword();

  $decode = base64_decode( $base64 );
  
  $size = getImageSizeFromString( $decode );

  //todo: err if the filesize is too large
  
  var_dump( $size );

  if (empty( $size[ "mime"] ) || strpos( $size[ "mime" ], 'image/' ) !== 0) {
    die( "Base64 value is not a valid image" );
  }

  //todo: err if the mimetype is not png, jpg, jpeg, gif, or webp

  $ext = substr( $size[ "mime" ], 6 );

  //PUT IN GALLERY

  require_once( ABSPATH . "/wp-load.php" );
  require_once( ABSPATH . "wp-admin/includes/admin.php" );
  require_once( ABSPATH . "/wp-admin/includes/image.php" );
  require_once( ABSPATH . "/wp-admin/includes/file.php" );
  require_once( ABSPATH . "/wp-admin/includes/media.php" );
  
  $image = wp_upload_bits( "$filename.$ext", null, $decode );

  $attachment = array(
    'post_mime_type' => $size[ "mime" ],
    'post_title' => sanitize_file_name( substr( $filename, 0, 20 ) ),
    'post_content' => '',
    'post_status' => 'inherit'
  );

  $attach_id = wp_insert_attachment( $attachment, $image[ "file" ] );
  $attach_data = wp_generate_attachment_metadata( $attach_id, $image[ "file" ] );
  wp_update_attachment_metadata( $attach_id, $attach_data );

  return $image[ "url" ];

}

add_filter( 'get_avatar', 'slashauth_get_avatar', 10, 5 );
function slashauth_get_avatar( $avatar, $id_or_email, $size, $default ) {
    //If is email, try and find user ID
    if( ! is_numeric( $id_or_email ) && is_email( $id_or_email ) ){
        $user  =  get_user_by( 'email', $id_or_email );
        if( $user ){
            $id_or_email = $user->ID;
        }
    }

    //if not user ID, return
    if( ! is_numeric( $id_or_email ) ){
        return $avatar;
    }

    //Find URL of saved avatar in user meta
    $saved = get_user_meta( $id_or_email, 'slashauth_avatar', true );
    //check if it is a URL
    if( filter_var( $saved, FILTER_VALIDATE_URL ) ) {
        //return saved image
        return sprintf( '<img src="%" alt="%" />', esc_url( $saved ), esc_attr( $alt ) );
    }

    //return normal
    return $avatar;
}

function slashauth_register_settings() {
        add_option( 'slashauth_website_name', get_bloginfo( 'name' ) );
        add_option( 'slashauth_website_bio', get_bloginfo( 'description' ) );
        add_option( 'slashauth_website_image', '' );
        add_option( 'slashauth_redirect', 'https://' . $_SERVER[ "HTTP_HOST" ] );
        add_option( 'slashauth_container_style', '' );
        add_option( 'slashauth_qr_style', 'width: 100%;' );
        add_option( 'slashauth_caption_container_style', 'margin-top: -20px; margin-bottom: 20px;' );
        add_option( 'slashauth_caption_style', 'display: inline-block; box-sizing: border-box; width: calc( 90% - 30px ); height: 40px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; border: 1px solid black; padding: 5px; vertical-align: middle;' );
        add_option( 'slashauth_copier_style', 'box-sizing: border-box; margin-left: 10px; margin-right: 10px; vertical-align: middle; width: 10%; height: 40px;' );
        register_setting( 'slashauth_options_group', 'slashauth_website_name', 'slashauth_callback' );
        register_setting( 'slashauth_options_group', 'slashauth_website_bio', 'slashauth_callback' );
        register_setting( 'slashauth_options_group', 'slashauth_website_image', 'slashauth_callback' );
        register_setting( 'slashauth_options_group', 'slashauth_redirect', 'slashauth_callback' );
        register_setting( 'slashauth_options_group', 'slashauth_container_style', 'slashauth_callback' );
        register_setting( 'slashauth_options_group', 'slashauth_qr_style', 'slashauth_callback' );
        register_setting( 'slashauth_options_group', 'slashauth_caption_container_style', 'slashauth_callback' );
        register_setting( 'slashauth_options_group', 'slashauth_caption_style', 'slashauth_callback' );
        register_setting( 'slashauth_options_group', 'slashauth_copier_style', 'slashauth_callback' );
}
add_action( 'admin_init', 'slashauth_register_settings' );

function slashauth_register_options_page() {
        add_options_page( 'Slashtags login', 'Slashtags login', 'manage_options', 'slashauth', 'slashauth_options_page' );
}
add_action('admin_menu', 'slashauth_register_options_page');

function slashauth_options_page()
{
?>
    <h2 style="text-decoration: underline;">Slashtags login</h2>
    <form method="post" action="options.php">
    <?php settings_fields( 'slashauth_options_group' ); ?>
    <h3>
            Daemon status <span id="daemon_running" style="color: red; font-size: 30px;">&bull;</span> <span id="connected_to_slashtags_network" style="color: red; font-size: 30px;">&bull;</span>
    </h3>
    <p>The first light indicates if your daemon is running (green is good, red is bad). The second light indicates if your daemon is connected to the slashtags network (again, green is good, red is bad). Sometimes it takes a few minutes for the second light to turn green.</p>
    <p>If neither light is green, ask your system administrator for help. He will need to install the slashtags daemon. Instructions are below.</p>
    <h3>
            Daemon installation instructions
    </h3>
    <p>
            Hello!
    </p>
    <h3>
            Set your website's slashtag profile
    </h3>
    <table>
            <tr valign="middle">
                    <th scope="row">
                            <label for="slashauth_website_name">
                                    Website name
                            </label>
                    </th>
                    <td>
                            <input type="text" id="slashauth_website_name" name="slashauth_website_name" value="<?php echo get_option( 'slashauth_website_name' ); ?>" />
                    </td>
            </tr>
    </table>
    <table>
            <tr valign="middle">
                    <th scope="row">
                            <label for="slashauth_website_bio">
                                    What is your website about?
                            </label>
                    </th>
                    <td>
                            <input type="text" id="slashauth_website_bio" name="slashauth_website_bio" value="<?php echo get_option( 'slashauth_website_bio' ); ?>" />
                    </td>
            </tr>
    </table>
    <table>
            <tr valign="middle">
                    <th scope="row">
                            <label for="slashauth_website_image">
                                    Website image (paste a url)
                            </label>
                    </th>
                    <td>
                            <input type="text" id="slashauth_website_image" name="slashauth_website_image" value="<?php echo get_option( 'slashauth_website_image' ); ?>" />
                    </td>
            </tr>
    </table>
    <p>
      <button type="button" id="save_site_slashtag_profile">Save your site's slashtag profile</button>
    </p>
    <script>
      async function encodeBase64( file ) {
        //the next three lines handle the case where an image is passed in from an external site
        if ( "files" in file ) {
                file = file.files[ 0 ];
        }
        var b64 = "";
        var imgReader = new FileReader();
        imgReader.onloadend = function() {
                b64 = imgReader.result.toString();
        }
        imgReader.readAsDataURL( file );
        async function isb64Ready() {
          return new Promise( function( resolve, reject ) {
            if ( !b64 ) {
              setTimeout( async function() {
                var msg = await isb64Ready();
                resolve( msg );
              }, 50 );
            } else {
              resolve( b64 );
            }
          });
        }
        var b64_is_ready = await isb64Ready();
        return b64;
      }
      function isValidJson( content ) {
            if ( !content ) return;
            try {  
                    var json = JSON.parse( content );
            } catch ( e ) {
                    return;
            }
            return true;
      }
      async function getData( url ) {
        var rtext = "";
        function inner_get( url ) {
          var xhttp = new XMLHttpRequest();
          xhttp.open( "GET", url, true );
          xhttp.send();
          return xhttp;
        }
        var data = inner_get( url );
        data.onerror = function( e ) {
          rtext = "error";
        }
        async function isResponseReady() {
          return new Promise( function( resolve, reject ) {
            if ( rtext == "error" ) {
              resolve( rtext );
            }
            if ( !data.responseText ) {
              setTimeout( async function() {
                var msg = await isResponseReady();
                resolve( msg );
              }, 50 );
            } else {
              resolve( data.responseText );
            }
          });
        }
        var rtext = await isResponseReady();
        return rtext;
      }
      function postJson( url, json ) {
        return new Promise( function( resolve, reject ) {
          var xhttp = new XMLHttpRequest();
          xhttp.onreadystatechange = function() {
            if ( this.readyState == 4 && ( this.status >= 200 && this.status < 300 ) ) {
              resolve( xhttp.responseText );
            }
          }
          xhttp.open( `POST`, url, true );
          xhttp.send( json );
        });
      }
      async function getDaemonStatus() {
        var status = await getData( "http://localhost:10249/status" );
        if ( !isValidJson( status ) ) {
          document.getElementById( "daemon_running" ).style.color = "red";
          document.getElementById( "connected_to_slashtags_network" ).style.color = "red";
          return;
        }
        status = JSON.parse( status );
        if ( status[ "daemon_running" ] ) {
          document.getElementById( "daemon_running" ).style.color = "#0bfc03";
        }
        if ( status[ "connected_to_dht_network" ] ) {
          document.getElementById( "connected_to_slashtags_network" ).style.color = "#0bfc03";
        }
      }
      function checkStatusOnLoop() {
        getDaemonStatus();
        setTimeout( function() {checkStatusOnLoop()}, 2000 );
      }
      checkStatusOnLoop();
      async function setProfile() {
        var url = document.getElementById( "slashauth_website_image" ).value
        var xhr = new XMLHttpRequest();
        xhr.onload = async function() {
          var image = await encodeBase64( xhr.response );
          var profile = {}
          profile[ "name" ] = document.getElementById( "slashauth_website_name" ).value;
          profile[ "bio" ] = document.getElementById( "slashauth_website_bio" ).value;
          profile[ "image" ] = image;
          await postJson( "http://localhost:10249/profile", JSON.stringify( profile ) );
          document.getElementsByName( "submit" )[ 0 ].click();
        }
        xhr.open('GET', url );
        xhr.responseType = 'blob';
        xhr.send();
      }
      document.getElementById( "save_site_slashtag_profile" ).onclick = function() {
        if ( document.getElementById( "daemon_running" ).style.color == "red" || document.getElementById( "connected_to_slashtags_network" ).style.color == "red" ) {
          alert( `You cannot set your slashtag profile until the daemon is running, see the indicator lights next to "Daemon status"` );
          return;
        }
        setProfile();
      }
    </script>
    <h3>
            Redirect
    </h3>
    <p>Where should users be redirected to when they log in?</p>
    <table>
            <tr valign="middle">
                    <th scope="row">
                            <label for="slashauth_redirect">
                                    Slashtags login redirect
                            </label>
                    </th>
                    <td>
                            <input type="text" id="slashauth_redirect" name="slashauth_redirect" value="<?php echo get_option( 'slashauth_redirect' ); ?>" />
                    </td>
            </tr>
      </table>
      <h3>
              Css
      </h3>
      <p>Adjust the css of the div element that contains the qr code and the caption. Resizing this will resize the qr code and the caption simultaneously.</p>
      <table>
              <tr valign="middle">
                      <th scope="row">
                              <label for="slashauth_container_style">
                                      Container css
                              </label>
                      </th>
                      <td>
                              <input type="text" id="slashauth_container_style" name="slashauth_container_style" value="<?php echo get_option( 'slashauth_container_style' ); ?>" />
                      </td>
              </tr>
      </table>
      <p>Adjust the css of the qr code image that appears in place of the shortcode. Note that this size attribute is independent of the caption so if you make one smaller, consider making the other smaller too.</p>
      <table>
              <tr valign="middle">
                      <th scope="row">
                              <label for="slashauth_qr_style">
                                      QR code css
                              </label>
                      </th>
                      <td>
                              <input type="text" id="slashauth_qr_style" name="slashauth_qr_style" value="<?php echo get_option( 'slashauth_qr_style' ); ?>" />
                      </td>
              </tr>
    </table>
    <p>Adjust the css of the html element below the qr code that contains the caption and the copy button</p>
    <table>
            <tr valign="middle">
                    <th scope="row">
                            <label for="slashauth_caption_container_style">
                                    Caption container css
                            </label>
                    </th>
                    <td>
                            <input type="text" id="slashauth_caption_container_style" name="slashauth_caption_container_style" value="<?php echo get_option( 'slashauth_caption_container_style' ); ?>" />
                    </td>
            </tr>
    </table>
    <p>Adjust the css of the caption that appears below the qr code</p>
    <table>
            <tr valign="middle">
                    <th scope="row">
                            <label for="slashauth_caption_style">
                                    Caption css
                            </label>
                    </th>
                    <td>
                            <input type="text" id="slashauth_caption_style" name="slashauth_caption_style" value="<?php echo get_option( 'slashauth_caption_style' ); ?>" />
                    </td>
            </tr>
    </table>
    <p>Adjust the css of the copy button that appears below the qr code</p>
    <table>
            <tr valign="middle">
                    <th scope="row">
                            <label for="slashauth_copier_style">
                                    Copy button css
                            </label>
                    </th>
                    <td>
                            <input type="text" id="slashauth_copier_style" name="slashauth_copier_style" value="<?php echo get_option( 'slashauth_copier_style' ); ?>" />
                    </td>
            </tr>
    </table>
    <?php  submit_button(); ?>
    <h3>
      Instructions
    </h3>
    <p>Add the following shortcode to any page on your site.</p>
    <pre style="margin-left: 50px;">[slashauth]</pre>
    <p>When a user visits the page, the shortcode will display as a clickable lightning login qr code. Place the shortcode inside one of your own elements to use standard wordpress css tools or website builders for modifying its size, position, etc. The css property of the element containing the link is #slashauth-auth-qr. The css id of the link is #slashauth-auth-link. The css id of the image is #slashauth-auth-image. There will also be a caption underneath the image containing the text of the slashauth string. Its css id is #slashauth-auth-caption.</p>
    <p>Users who scan the qr code with a device that supports the slashauth protocol will automatically get a new account. If they have a profile on the slashtags network, the plugin will automatically pull in their name, bio, and profile picture, or, if they do not have a profile on the slashtags network, the plugin will give them a random username and secure password. If they've signed in with their slashtag before, they will be signed into their existing account without ever needing to remember -- or even see -- their password. After logging in, the user will be redirected to whatever page you specify in settings.</p>
        </form>
<?php
}

?>
