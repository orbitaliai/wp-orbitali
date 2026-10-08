<?php
/**
 * Classic editor meta box markup. Populated client-side by
 * classic-editor-panel.js against the same REST endpoints the block
 * editor panel uses.
 *
 * @package Orbitali
 * @var WP_Post $post
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="orbitali-panel" id="orbitali-classic-panel" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
	<p><?php esc_html_e( 'Loading Orbitali status…', 'orbitali' ); ?></p>
</div>
