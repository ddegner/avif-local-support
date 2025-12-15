<?php
/**
 * Settings tab template.
 *
 * @package Ddegner\AvifLocalSupport
 */

defined('ABSPATH') || exit;
?>
<div id="avif-local-support-tab-settings" class="avif-local-support-tab active">
    <div class="metabox-holder">
        <form action="options.php" method="post">
            <?php settings_fields('aviflosu_settings'); ?>

            <div class="postbox">
                <h2 class="avif-header"><span><?php esc_html_e('Serve AVIF files', 'avif-local-support'); ?></span></h2>
                <div class="inside">
                    <table class="form-table" role="presentation">
                        <?php do_settings_fields('avif-local-support', 'aviflosu_main'); ?>
                    </table>
                </div>
            </div>

            <div class="postbox">
                <h2 class="avif-header"><span><?php esc_html_e('Engine Selection', 'avif-local-support'); ?></span></h2>
                <div class="inside">
                    <table class="form-table" role="presentation">
                        <?php do_settings_fields('avif-local-support', 'aviflosu_engine'); ?>
                    </table>
                </div>
            </div>

            <div class="postbox">
                <h2 class="avif-header"><span><?php esc_html_e('Conversion Settings', 'avif-local-support'); ?></span>
                </h2>
                <div class="inside">
                    <table class="form-table" role="presentation">
                        <?php do_settings_fields('avif-local-support', 'aviflosu_conversion'); ?>
                    </table>
                </div>
            </div>

            <div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <?php submit_button('', 'primary', 'submit', false); ?>
            </div>
        </form>

        <div style="margin-top:20px;padding-top:20px;border-top:1px solid #c3c4c7;">
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="display:inline"
                onsubmit="return confirm('<?php esc_attr_e('Reset all settings to defaults?', 'avif-local-support'); ?>');">
                <input type="hidden" name="action" value="aviflosu_reset_defaults" />
                <?php wp_nonce_field('aviflosu_reset_defaults', '_wpnonce', false, true); ?>
                <button type="submit"
                    class="button"><?php esc_html_e('Restore defaults', 'avif-local-support'); ?></button>
            </form>
        </div>
    </div>
</div>