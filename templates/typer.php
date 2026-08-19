<?php
if (!defined('ABSPATH')) exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class('decka-typer-standalone'); ?>>
<?php echo do_shortcode('[decka_typer]'); ?>
<?php wp_footer(); ?>
</body>
</html>
