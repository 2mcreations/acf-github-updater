<?php
/**
 * Plugin Name: ACF Pro GitHub Updater
 * Description: Aggiorna Advanced Custom Fields PRO dalle release GitHub
 * Version: 1.0.0
 * Author: 2mcreations
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/class-acf-github-updater.php';

new ACF_GitHub_Updater();
