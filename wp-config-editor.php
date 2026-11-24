<?php
/**
 * Plugin Name: WP Config Editor
 * Plugin URI: https://github.com/Azanumer/wp-config-editor
 * Description: Safely edit wp-config.php constants from the admin — with automatic backups, a syntax check before every write, and database credentials and salts locked by default.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * Text Domain: wp-config-editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WCE_VERSION', '1.0.0' );

/**
 * Locate wp-config.php: in ABSPATH, or one level above it (the standard
 * alternative location WordPress itself supports).
 *
 * @return string|false Absolute path, or false when not found.
 */
function wce_config_path() {
    $candidates = array(
        ABSPATH . 'wp-config.php',
        dirname( ABSPATH ) . '/wp-config.php',
    );
    foreach ( $candidates as $path ) {
        if ( is_file( $path ) ) {
            return $path;
        }
    }
    return false;
}

/**
 * Constants that are locked by default: database credentials, salts and
 * ABSPATH. Editing them from a web form is how sites get bricked.
 * Unlock via the wce_protected_constants filter if you really need to.
 */
function wce_protected_constants() {
    $defaults = array(
        'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'DB_CHARSET', 'DB_COLLATE',
        'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
        'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
        'ABSPATH',
    );
    return apply_filters( 'wce_protected_constants', $defaults );
}

/**
 * Parse define() statements out of the config file.
 *
 * Returns an array of name => array('value' => mixed, 'raw' => string,
 * 'prefix' => string, 'suffix' => string, 'line' => int,
 * 'protected' => bool, 'parsable' => bool). Only scalar values (string,
 * int, float, bool, null) are parsed; anything else (arrays, expressions,
 * function calls) is reported as unparsable and never rewritten.
 * The greedy value match takes the LAST ')' before ';', so a salt
 * containing ');' still parses correctly.
 */
function wce_parse_config( $path ) {
    $lines     = file( $path );
    $constants = array();
    $protected = wce_protected_constants();

    foreach ( $lines as $i => $line ) {
        if ( ! preg_match( "/^(\s*define\s*\(\s*['\"]([A-Z0-9_]+)['\"]\s*,\s*)(.+)(\s*\)\s*;.*)$/", $line, $m ) ) {
            continue;
        }
        $name = $m[2];
        $raw  = trim( $m[3] );
        $constants[ $name ] = array(
            'value'     => wce_parse_scalar( $raw ),
            'raw'       => $raw,
            'prefix'    => $m[1],
            'suffix'    => $m[4],
            'line'      => $i,
            'protected' => in_array( $name, $protected, true ),
            'parsable'  => wce_parse_scalar( $raw ) !== '__WCE_UNPARSABLE__',
        );
    }
    return $constants;
}

/**
 * Parse a raw PHP scalar literal. Returns the value, or the sentinel
 * '__WCE_UNPARSABLE__' when the literal is not a plain scalar.
 */
function wce_parse_scalar( $raw ) {
    $raw = trim( $raw );
    if ( preg_match( "/^'((?:[^'\\\\]|\\\\.)*)'$/s", $raw, $m ) ) {
        return stripcslashes( $m[1] );
    }
    if ( preg_match( '/^"((?:[^"\\\\]|\\\\.)*)"$/s', $raw, $m ) ) {
        return stripcslashes( $m[1] );
    }
    if ( strcasecmp( $raw, 'true' ) === 0 ) {
        return true;
    }
    if ( strcasecmp( $raw, 'false' ) === 0 ) {
        return false;
    }
    if ( strcasecmp( $raw, 'null' ) === 0 ) {
        return null;
    }
    if ( preg_match( '/^-?[0-9]+$/', $raw ) ) {
        return (int) $raw;
    }
    if ( preg_match( '/^-?[0-9]*\.[0-9]+$/', $raw ) ) {
        return (float) $raw;
    }
    return '__WCE_UNPARSABLE__';
}

/**
 * Render a PHP value as source code for a define() statement.
 */
function wce_format_value( $value ) {
    if ( is_bool( $value ) ) {
        return $value ? 'true' : 'false';
    }
    if ( $value === null ) {
        return 'null';
    }
    if ( is_int( $value ) || is_float( $value ) ) {
        return (string) $value;
    }
    return "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), (string) $value ) . "'";
}

/**
 * Syntax-check a PHP source string.
 *
 * Uses `php -l` on a temp file when the CLI is available (it is on most
 * hosts); otherwise falls back to a token-based brace/paren balance check.
 *
 * @return string|null Error message, or null when the source looks valid.
 */
function wce_lint_source( $source ) {
    $tmp = tempnam( sys_get_temp_dir(), 'wce-lint-' );
    if ( $tmp === false ) {
        return __( 'Could not create a temporary file for the syntax check.', 'wp-config-editor' );
    }
    file_put_contents( $tmp, $source );

    $php = trim( (string) shell_exec( 'command -v php 2>/dev/null' ) );
    if ( $php !== '' ) {
        $output = array();
        $code   = 0;
        exec( escapeshellarg( $php ) . ' -l ' . escapeshellarg( $tmp ) . ' 2>&1', $output, $code );
        unlink( $tmp );
        if ( $code !== 0 ) {
            return implode( "\n", $output );
        }
        return null;
    }
    unlink( $tmp );

    // Fallback: tokenise and check bracket balance.
    $tokens = @token_get_all( $source );
    if ( $tokens === false ) {
        return __( 'Could not tokenise the file for the syntax check.', 'wp-config-editor' );
    }
    $stack = array();
    $pairs = array( ')' => '(', ']' => '[', '}' => '{' );
    foreach ( $tokens as $token ) {
        if ( ! is_string( $token ) ) {
            continue;
        }
        if ( in_array( $token, array( '(', '[', '{' ), true ) ) {
            $stack[] = $token;
        } elseif ( isset( $pairs[ $token ] ) ) {
            if ( empty( $stack ) || array_pop( $stack ) !== $pairs[ $token ] ) {
                return __( 'Unbalanced brackets in the edited file.', 'wp-config-editor' );
            }
        }
    }
    if ( ! empty( $stack ) ) {
        return __( 'Unbalanced brackets in the edited file.', 'wp-config-editor' );
    }
    return null;
}

/**
 * Write a backup of the current config into wp-content, with a random
 * token in the name so it cannot be guessed.
 *
 * @return string|false Backup path, or false on failure.
 */
function wce_make_backup( $path ) {
    $dir  = WP_CONTENT_DIR;
    $name = 'wp-config-backup-' . gmdate( 'Y-m-d-His' ) . '-' . wp_generate_password( 8, false ) . '.php';
    $dest = trailingslashit( $dir ) . $name;
    if ( copy( $path, $dest ) ) {
        return $dest;
    }
    return false;
}

/**
 * List existing backups (newest first).
 */
function wce_list_backups() {
    $files = glob( trailingslashit( WP_CONTENT_DIR ) . 'wp-config-backup-*.php' );
    if ( ! $files ) {
        return array();
    }
    rsort( $files );
    return $files;
}

/**
 * Apply edits to the config file.
 *
 * @param array $edits Map of constant name => new value (already typed).
 * @param array $add   Map of new constant name => value to append.
 * @return string|true True on success, error message string on failure.
 */
function wce_apply_edits( $edits, $add = array() ) {
    $path = wce_config_path();
    if ( $path === false ) {
        return __( 'wp-config.php was not found.', 'wp-config-editor' );
    }
    if ( ! is_writable( $path ) ) {
        return __( 'wp-config.php is not writable by the web server.', 'wp-config-editor' );
    }

    $constants = wce_parse_config( $path );
    $protected = wce_protected_constants();
    $lines     = file( $path );
    $changed   = array();

    foreach ( $edits as $name => $value ) {
        $name = strtoupper( sanitize_key( $name ) );
        if ( in_array( $name, $protected, true ) ) {
            return sprintf( __( '%s is protected and cannot be edited here.', 'wp-config-editor' ), $name );
        }
        if ( ! isset( $constants[ $name ] ) ) {
            return sprintf( __( '%s is not defined in wp-config.php.', 'wp-config-editor' ), $name );
        }
        if ( ! $constants[ $name ]['parsable'] ) {
            return sprintf( __( '%s has a value this editor cannot safely rewrite.', 'wp-config-editor' ), $name );
        }
        $line_no   = $constants[ $name ]['line'];
        $new_value = wce_format_value( $value );
        // Rebuild the line from its captured prefix/suffix so the value is
        // swapped without touching the surrounding formatting or comments.
        $lines[ $line_no ] = $constants[ $name ]['prefix'] . $new_value . $constants[ $name ]['suffix'] . "\n";
        $changed[] = $name;
    }

    foreach ( $add as $name => $value ) {
        $name = strtoupper( sanitize_key( $name ) );
        if ( ! preg_match( '/^[A-Z][A-Z0-9_]*$/', $name ) ) {
            return sprintf( __( '%s is not a valid constant name.', 'wp-config-editor' ), $name );
        }
        if ( isset( $constants[ $name ] ) ) {
            return sprintf( __( '%s is already defined in wp-config.php.', 'wp-config-editor' ), $name );
        }
        $new_line = "define( '{$name}', " . wce_format_value( $value ) . " );\n";
        $inserted = false;
        foreach ( $lines as $i => $line ) {
            if ( strpos( $line, "That's all, stop editing" ) !== false || strpos( $line, 'wp-settings.php' ) !== false ) {
                array_splice( $lines, $i, 0, array( $new_line ) );
                $inserted = true;
                break;
            }
        }
        if ( ! $inserted ) {
            $lines[] = $new_line;
        }
        $changed[] = $name;
    }

    if ( empty( $changed ) ) {
        return __( 'Nothing to change.', 'wp-config-editor' );
    }

    $source = implode( '', $lines );

    // Lint BEFORE writing — a broken wp-config.php takes the whole site down.
    $error = wce_lint_source( $source );
    if ( $error !== null ) {
        return sprintf( __( 'Syntax check failed — nothing was written. %s', 'wp-config-editor' ), $error );
    }

    $backup = wce_make_backup( $path );
    if ( $backup === false ) {
        return __( 'Could not create a backup — nothing was written.', 'wp-config-editor' );
    }

    if ( file_put_contents( $path, $source, LOCK_EX ) === false ) {
        return __( 'Failed to write wp-config.php. A backup was kept in wp-content.', 'wp-config-editor' );
    }

    return true;
}

/**
 * Restore a backup file (must live in wp-content and match the naming).
 */
function wce_restore_backup( $backup ) {
    $path = wce_config_path();
    if ( $path === false ) {
        return __( 'wp-config.php was not found.', 'wp-config-editor' );
    }
    $backup = realpath( $backup );
    if ( $backup === false || strpos( $backup, realpath( WP_CONTENT_DIR ) ) !== 0 || strpos( basename( $backup ), 'wp-config-backup-' ) !== 0 ) {
        return __( 'That is not a recognised backup file.', 'wp-config-editor' );
    }
    $source = file_get_contents( $backup );
    if ( $source === false ) {
        return __( 'Could not read the backup file.', 'wp-config-editor' );
    }
    $error = wce_lint_source( $source );
    if ( $error !== null ) {
        return sprintf( __( 'Backup failed the syntax check — nothing was written. %s', 'wp-config-editor' ), $error );
    }
    $new_backup = wce_make_backup( $path );
    if ( $new_backup === false ) {
        return __( 'Could not create a backup of the current file — nothing was written.', 'wp-config-editor' );
    }
    if ( file_put_contents( $path, $source, LOCK_EX ) === false ) {
        return __( 'Failed to write wp-config.php.', 'wp-config-editor' );
    }
    return true;
}

/**
 * Handle the settings form submission.
 */
function wce_handle_save() {
    check_admin_referer( 'wce_save_config' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to do this.', 'wp-config-editor' ) );
    }

    $edits = array();
    if ( isset( $_POST['wce_values'] ) && is_array( $_POST['wce_values'] ) ) {
        foreach ( $_POST['wce_values'] as $name => $raw ) {
            $name  = strtoupper( sanitize_key( $name ) );
            $type  = isset( $_POST['wce_types'][ $name ] ) ? sanitize_key( $_POST['wce_types'][ $name ] ) : 'string';
            $raw   = wp_unslash( $raw );
            switch ( $type ) {
                case 'bool':
                    $edits[ $name ] = ( $raw === 'true' || $raw === '1' );
                    break;
                case 'int':
                    $edits[ $name ] = (int) $raw;
                    break;
                case 'float':
                    $edits[ $name ] = (float) $raw;
                    break;
                case 'null':
                    $edits[ $name ] = null;
                    break;
                default:
                    $edits[ $name ] = (string) $raw;
                    break;
            }
        }
    }

    $add = array();
    $new_name = isset( $_POST['wce_new_name'] ) ? strtoupper( sanitize_key( wp_unslash( $_POST['wce_new_name'] ) ) ) : '';
    if ( $new_name !== '' ) {
        $new_value = isset( $_POST['wce_new_value'] ) ? wp_unslash( $_POST['wce_new_value'] ) : '';
        $new_type  = isset( $_POST['wce_new_type'] ) ? sanitize_key( $_POST['wce_new_type'] ) : 'string';
        $add[ $new_name ] = $new_type === 'bool' ? ( $new_value === 'true' || $new_value === '1' )
            : ( $new_type === 'int' ? (int) $new_value
            : ( $new_type === 'float' ? (float) $new_value
            : ( $new_type === 'null' ? null : (string) $new_value ) ) );
    }

    // Drop edits whose value did not actually change.
    $path      = wce_config_path();
    $constants = $path !== false ? wce_parse_config( $path ) : array();
    foreach ( $edits as $name => $value ) {
        if ( isset( $constants[ $name ] ) && $constants[ $name ]['value'] === $value ) {
            unset( $edits[ $name ] );
        }
    }

    $result   = wce_apply_edits( $edits, $add );
    $redirect = add_query_arg( 'page', 'wce-settings', admin_url( 'tools.php' ) );
    if ( $result === true ) {
        $redirect = add_query_arg( 'wce_saved', count( $edits ) + count( $add ), $redirect );
    } else {
        $redirect = add_query_arg( 'wce_error', rawurlencode( $result ), $redirect );
    }
    wp_safe_redirect( $redirect );
    exit;
}
add_action( 'admin_post_wce_save_config', 'wce_handle_save' );

function wce_handle_restore() {
    check_admin_referer( 'wce_restore_backup' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to do this.', 'wp-config-editor' ) );
    }
    $backup = isset( $_POST['wce_backup'] ) ? wp_unslash( $_POST['wce_backup'] ) : '';
    $result = wce_restore_backup( $backup );
    $redirect = add_query_arg( 'page', 'wce-settings', admin_url( 'tools.php' ) );
    if ( $result === true ) {
        $redirect = add_query_arg( 'wce_restored', 1, $redirect );
    } else {
        $redirect = add_query_arg( 'wce_error', rawurlencode( $result ), $redirect );
    }
    wp_safe_redirect( $redirect );
    exit;
}
add_action( 'admin_post_wce_restore_backup', 'wce_handle_restore' );

/**
 * Settings page under Tools.
 */
function wce_register_menu() {
    add_management_page(
        __( 'Config Editor', 'wp-config-editor' ),
        __( 'Config Editor', 'wp-config-editor' ),
        'manage_options',
        'wce-settings',
        'wce_render_settings_page'
    );
}
add_action( 'admin_menu', 'wce_register_menu' );

function wce_value_type( $value ) {
    if ( is_bool( $value ) ) {
        return 'bool';
    }
    if ( is_int( $value ) ) {
        return 'int';
    }
    if ( is_float( $value ) ) {
        return 'float';
    }
    if ( $value === null ) {
        return 'null';
    }
    return 'string';
}

function wce_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $path      = wce_config_path();
    $constants = $path !== false ? wce_parse_config( $path ) : array();
    $backups   = wce_list_backups();
    $saved     = isset( $_GET['wce_saved'] ) ? (int) $_GET['wce_saved'] : null;
    $restored  = isset( $_GET['wce_restored'] );
    $error     = isset( $_GET['wce_error'] ) ? sanitize_text_field( wp_unslash( $_GET['wce_error'] ) ) : '';
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Config Editor', 'wp-config-editor' ); ?></h1>

        <?php if ( $saved !== null ) : ?>
            <div class="notice notice-success is-dismissible"><p>
                <?php printf( esc_html__( '%d constant(s) updated. A backup was saved in wp-content.', 'wp-config-editor' ), $saved ); ?>
            </p></div>
        <?php endif; ?>
        <?php if ( $restored ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Backup restored. The previous file was backed up first.', 'wp-config-editor' ); ?></p></div>
        <?php endif; ?>
        <?php if ( $error !== '' ) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $error ); ?></p></div>
        <?php endif; ?>

        <?php if ( $path === false ) : ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'wp-config.php was not found. This plugin only works on a standard WordPress install.', 'wp-config-editor' ); ?></p></div>
        <?php else : ?>
            <p><?php printf( esc_html__( 'Editing: %s', 'wp-config-editor' ), '<code>' . esc_html( $path ) . '</code>' ); ?></p>
            <div class="notice notice-warning"><p><?php esc_html_e( 'A mistake in wp-config.php can take your whole site down. Every save is syntax-checked first and a backup is made in wp-content — but keep your own off-server backup too.', 'wp-config-editor' ); ?></p></div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'wce_save_config' ); ?>
                <input type="hidden" name="action" value="wce_save_config">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Constant', 'wp-config-editor' ); ?></th>
                            <th><?php esc_html_e( 'Value', 'wp-config-editor' ); ?></th>
                            <th><?php esc_html_e( 'Type', 'wp-config-editor' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $constants as $name => $c ) : ?>
                            <tr>
                                <td><code><?php echo esc_html( $name ); ?></code><?php if ( $c['protected'] ) : ?> <span class="description"><?php esc_html_e( '(locked)', 'wp-config-editor' ); ?></span><?php endif; ?></td>
                                <td>
                                    <?php if ( $c['protected'] || ! $c['parsable'] ) : ?>
                                        <code><?php echo esc_html( $c['raw'] ); ?></code>
                                        <?php if ( ! $c['parsable'] ) : ?>
                                            <span class="description"><?php esc_html_e( 'Not a plain value — cannot be edited here.', 'wp-config-editor' ); ?></span>
                                        <?php endif; ?>
                                    <?php elseif ( is_bool( $c['value'] ) ) : ?>
                                        <select name="wce_values[<?php echo esc_attr( $name ); ?>]">
                                            <option value="true" <?php selected( $c['value'], true ); ?>>true</option>
                                            <option value="false" <?php selected( $c['value'], false ); ?>>false</option>
                                        </select>
                                        <input type="hidden" name="wce_types[<?php echo esc_attr( $name ); ?>]" value="bool">
                                    <?php else : ?>
                                        <input type="text" name="wce_values[<?php echo esc_attr( $name ); ?>]" value="<?php echo esc_attr( $c['value'] === null ? '' : (string) $c['value'] ); ?>" class="regular-text">
                                        <input type="hidden" name="wce_types[<?php echo esc_attr( $name ); ?>]" value="<?php echo esc_attr( wce_value_type( $c['value'] ) ); ?>">
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( wce_value_type( $c['value'] ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td><input type="text" name="wce_new_name" placeholder="NEW_CONSTANT" pattern="[A-Z][A-Z0-9_]*" title="<?php esc_attr_e( 'Uppercase letters, digits and underscores, starting with a letter.', 'wp-config-editor' ); ?>"></td>
                            <td><input type="text" name="wce_new_value" placeholder="<?php esc_attr_e( 'value', 'wp-config-editor' ); ?>" class="regular-text"></td>
                            <td>
                                <select name="wce_new_type">
                                    <option value="string">string</option>
                                    <option value="bool">bool</option>
                                    <option value="int">int</option>
                                    <option value="float">float</option>
                                    <option value="null">null</option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="description"><?php esc_html_e( 'Leave the last row empty to skip adding a constant. New constants are inserted before the "That\'s all, stop editing!" line.', 'wp-config-editor' ); ?></p>
                <?php submit_button( __( 'Save config', 'wp-config-editor' ), 'primary' ); ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Backups', 'wp-config-editor' ); ?></h2>
            <?php if ( empty( $backups ) ) : ?>
                <p><?php esc_html_e( 'No backups yet — one is created automatically before every save.', 'wp-config-editor' ); ?></p>
            <?php else : ?>
                <ul>
                    <?php foreach ( $backups as $backup ) : ?>
                        <li>
                            <code><?php echo esc_html( basename( $backup ) ); ?></code>
                            (<?php echo esc_html( size_format( filesize( $backup ) ) ); ?>)
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                                <?php wp_nonce_field( 'wce_restore_backup' ); ?>
                                <input type="hidden" name="action" value="wce_restore_backup">
                                <input type="hidden" name="wce_backup" value="<?php echo esc_attr( $backup ); ?>">
                                <?php submit_button( __( 'Restore', 'wp-config-editor' ), 'small', 'submit', false ); ?>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}
