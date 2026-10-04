<?php
// A static front page renders through templates/front-page.php. With Settings -> Reading
// "Your latest posts" core still loads this file (is_front_page() precedes is_home()), but
// that front page IS the posts index, so it renders as one (#1173).
get_template_part(pp_front_page_id() > 0 ? 'templates/front-page' : 'templates/home');
