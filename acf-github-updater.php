<?php
/**
 * Plugin Name: ACF Pro GitHub Updater
 * Description: Aggiorna Advanced Custom Fields PRO dalle release GitHub
 * Version: 1.0.5
 * Author: 2mcreations.it
 * Author URI: https://2mcreations.it
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-acf-github-updater.php';

new ACF_GitHub_Updater();
