<?php
/**
 * Settings page markup. $view is provided by Orbitali_Settings_Page::render().
 *
 * @package Orbitali
 * @var array $view
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap orbitali-settings">
	<h1><?php esc_html_e( 'Orbitali', 'orbitali' ); ?></h1>
	<p><?php esc_html_e( 'Connect your Orbitali account and choose which voice agent should receive the pages and posts you sync from this site.', 'orbitali' ); ?></p>

	<div id="orbitali-notice" class="notice" hidden></div>

	<h2><?php esc_html_e( 'Connection', 'orbitali' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'API key', 'orbitali' ); ?></th>
			<td>
				<?php if ( $view['has_key'] ) : ?>
					<p>
						<code id="orbitali-masked-key"><?php echo esc_html( $view['masked_key'] ); ?></code>
						<span id="orbitali-connection-badge" class="orbitali-badge <?php echo $view['connected'] ? 'orbitali-badge-ok' : 'orbitali-badge-error'; ?>">
							<?php echo $view['connected'] ? esc_html__( 'Connected', 'orbitali' ) : esc_html__( 'Not connected', 'orbitali' ); ?>
						</span>
					</p>
					<?php if ( ! $view['connected'] && $view['list_error'] ) : ?>
						<p class="description orbitali-error"><?php echo esc_html( $view['list_error'] ); ?></p>
					<?php endif; ?>
					<p>
						<button type="button" class="button" id="orbitali-change-key"><?php esc_html_e( 'Change key', 'orbitali' ); ?></button>
						<button type="button" class="button-link-delete" id="orbitali-disconnect"><?php esc_html_e( 'Disconnect', 'orbitali' ); ?></button>
					</p>
				<?php endif; ?>
				<div id="orbitali-key-form" <?php echo $view['has_key'] ? 'hidden' : ''; ?>>
					<input type="password" id="orbitali-api-key" class="regular-text" autocomplete="off" placeholder="orb_sk_..." />
					<button type="button" class="button button-primary" id="orbitali-connect"><?php esc_html_e( 'Connect', 'orbitali' ); ?></button>
					<p class="description"><?php esc_html_e( 'Create an API key from your Orbitali dashboard under Settings → API keys, then paste it here. The key is stored only on this server and is never shown again in full.', 'orbitali' ); ?></p>
				</div>
				<p class="description">
					<?php esc_html_e( "Orbitali's public API does not expose an organization name to API-key clients, so it cannot be shown here. A successful connection and the agent list below confirm the key is valid.", 'orbitali' ); ?>
				</p>
			</td>
		</tr>
	</table>

	<div id="orbitali-agent-section" <?php echo $view['connected'] ? '' : 'hidden'; ?>>
		<h2><?php esc_html_e( 'Destination agent', 'orbitali' ); ?></h2>
		<p><?php esc_html_e( 'Pages and posts you sync from this site will be added to this agent\'s knowledge base.', 'orbitali' ); ?></p>

		<div id="orbitali-agent-list">
			<?php if ( empty( $view['agents'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'No agents yet.', 'orbitali' ); ?></p>
			<?php else : ?>
				<ul class="orbitali-agent-list">
					<?php foreach ( $view['agents'] as $agent ) : ?>
						<li data-agent-id="<?php echo esc_attr( $agent['id'] ); ?>">
							<label>
								<input
									type="radio"
									name="orbitali_agent"
									value="<?php echo esc_attr( $agent['id'] ); ?>"
									<?php checked( $agent['id'], $view['selected_agent_id'] ); ?>
								/>
								<strong><?php echo esc_html( $agent['name'] ); ?></strong>
								<span class="orbitali-pill"><?php echo esc_html( $agent['status'] ); ?></span>
								<?php if ( null !== $agent['knowledgeDocumentCount'] ) : ?>
									<span class="description">
										<?php
										printf(
											/* translators: %d: number of knowledge documents */
											esc_html__( '%d document(s)', 'orbitali' ),
											(int) $agent['knowledgeDocumentCount']
										);
										?>
									</span>
								<?php endif; ?>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
				<button type="button" class="button button-primary" id="orbitali-use-agent"><?php esc_html_e( 'Use selected agent', 'orbitali' ); ?></button>
			<?php endif; ?>
		</div>

		<p>
			<button type="button" class="button" id="orbitali-toggle-create"><?php esc_html_e( 'Create new agent', 'orbitali' ); ?></button>
		</p>

		<div id="orbitali-create-agent-form" hidden>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="orbitali-new-name"><?php esc_html_e( 'Name', 'orbitali' ); ?></label></th>
					<td><input type="text" id="orbitali-new-name" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="orbitali-new-language"><?php esc_html_e( 'Language', 'orbitali' ); ?></label></th>
					<td>
						<select id="orbitali-new-language">
							<?php foreach ( Orbitali_Settings_Page::language_options() as $code => $label ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="orbitali-new-voice"><?php esc_html_e( 'Voice', 'orbitali' ); ?></label></th>
					<td>
						<select id="orbitali-new-voice">
							<?php foreach ( Orbitali_Settings_Page::voice_options() as $code => $label ) : ?>
								<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="orbitali-new-instructions"><?php esc_html_e( 'Instructions (optional)', 'orbitali' ); ?></label></th>
					<td>
						<textarea id="orbitali-new-instructions" class="large-text" rows="3" placeholder="<?php esc_attr_e( 'Answer questions using only the uploaded knowledge base…', 'orbitali' ); ?>"></textarea>
					</td>
				</tr>
			</table>
			<p class="description"><?php esc_html_e( 'This creates a static, knowledge-only agent as a draft. Phone number assignment and activation are managed separately in the Orbitali dashboard.', 'orbitali' ); ?></p>
			<button type="button" class="button button-primary" id="orbitali-create-agent"><?php esc_html_e( 'Create agent', 'orbitali' ); ?></button>
		</div>
	</div>

	<div id="orbitali-selected-agent-summary" <?php echo ( $view['connected'] && $view['selected_agent_id'] ) ? '' : 'hidden'; ?>>
		<h2><?php esc_html_e( 'Currently selected', 'orbitali' ); ?></h2>
		<p>
			<strong id="orbitali-selected-name"><?php echo esc_html( $view['selected_agent_name'] ); ?></strong>
			(<span id="orbitali-selected-status"><?php echo esc_html( $view['selected_agent_status'] ); ?></span>)
		</p>
	</div>
</div>
