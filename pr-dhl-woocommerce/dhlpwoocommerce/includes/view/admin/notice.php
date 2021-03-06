<?php if (!defined('ABSPATH')) { exit; } ?>
<div class="notice notice-warning is-dismissible"
     <?php if (!empty($notice_tag)) : ?>
     data-dhlpwc-dismissable-notice="<?php echo esc_attr($notice_tag) ?>"
     <?php endif ?>
>
    <p>
        <h4><?php echo _e('DHL for WooCommerce notice', 'dhlpwc') ?></h4>

        <ul>
            <?php foreach ($messages as $message) : ?>
            <li>
                - <?php echo esc_attr($message) ?>
            </li>
            <?php endforeach ?>
        </ul>

        <?php if (isset($admin_link)) : ?>
        <span>
            <?php echo sprintf(
                __('%sClick here%s to go to the settings screen.', 'dhlpwc'),
                '<a href="' . $admin_link . '">',
                '</a>'
            ) ?>
        </span>
        <?php endif ?>
    </p>
</div>
