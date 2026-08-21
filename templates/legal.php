<?php if (!defined('ABSPATH')) exit; ?><!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class(); ?>><?php wp_body_open(); ?>
<header class="dt-legal-header"><div><a href="<?php echo esc_url(home_url('/')); ?>"><img src="<?php echo esc_url(DT_Brand::logo_horizontal_url()); ?>" alt="TypujKosza.pl"></a><a class="dt-legal-back" href="<?php echo esc_url(home_url('/')); ?>">Wróć do Typera</a></div></header>
<main class="dt-legal-main"><article class="dt-legal-card"><?php while (have_posts()) : the_post(); ?><p class="dt-legal-kicker">TYPUJKOSZA.PL</p><h1><?php the_title(); ?></h1><div class="dt-legal-content"><?php the_content(); ?></div><?php endwhile; ?></article></main>
<?php wp_footer(); ?></body></html>
