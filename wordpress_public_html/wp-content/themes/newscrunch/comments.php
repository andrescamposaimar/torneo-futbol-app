<?php
/**
 * The template for displaying comments
 *
 * This is the template that displays the area of the page that contains both the current comments
 * and the comment form.
 *
 * @package Newscrunch
 */

/*
 * If the current post is protected by a password and
 * the visitor has not yet entered the password we will
 * return early without loading the comments.
 */
if ( post_password_required() ) {
    return;
}

if (have_comments()) : ?>
    <article class="comment-section">
        <div class="comment-title">
            <h3>
                <?php
                $newscrunch_comment_box_count = get_comments_number();
                if ('1' === $newscrunch_comment_box_count) {
                    printf(
                            /* translators: 1: title. */
                            esc_html__('1 comment', 'newscrunch' ),
                            '<span>' . esc_html(get_the_title()) . '</span>'
                    );
                } else {
                    printf(
                            /* translators: 1: comment count number, 2: title. */
                            esc_html(_nx('%1$s ' . __('comment','newscrunch'), '%1$s ' . __('comments','newscrunch'), $newscrunch_comment_box_count, __('comments title', 'newscrunch') )),
                            esc_html(number_format_i18n($newscrunch_comment_box_count)),
                            '<span>' . esc_html(get_the_title()) . '</span>'
                    );
                }
                ?>
            </h3>
        </div>
        <?php
        the_comments_navigation();
        wp_list_comments('type=comment&callback=newscrunch_comment_box');
        the_comments_navigation();

        // If comments are closed and there are comments, let's leave a little note, shall we?
        if (!comments_open()) :
            ?>
            <p class="no-comments"><?php esc_html_e('Comments are closed.', 'newscrunch' ); ?></p>
        <?php endif; ?>
    </article>
<?php endif;
echo '<article class="comment-form">';
  
    $fields     =   array(
        'author'    =>  '<p class="comment-form-author">
                            <label for="author">'.esc_html__('Name','newscrunch').'
                                <span class="required">
                                    <input type="text" name="author" value="" size="30" class="blog-form-control" aria-required="true" arai-invalid="false">
                                </span>
                            </label>
                        </p>',
        'email'     =>  '<p class="comment-form-email">
                            <label for="email">'.esc_html__('Email','newscrunch').'
                                <span class="required">
                                    <input type="email" name="email" value="" size="30" class="blog-form-control" aria-describedby="email-notes" aria-required="true" arai-invalid="false">
                                </span>
                            </label>
                        </p>'
    );
    function newscrunch_fields($fields) { 
        return $fields;
    }
    add_filter('comment_form_default_fields','newscrunch_fields');
    $defaults = array(
            'fields'                =>  apply_filters( 'comment_form_default_fields', $fields ),
            'comment_field'         =>  '<p class="comment-form-comment">
                                            <label for="comment">'.esc_html__('Comments','newscrunch').'
                                                <textarea id="comment" type="text" name="comment" value="" cols="45" rows="8" aria-required="true" arai-invalid="false"></textarea>
                                            </label>
                                        </p>',
            'logged_in_as'          =>  '<p class="comment-notes">' . esc_html__("Logged in as",'newscrunch'  ).' '.'<a href="'. esc_url(admin_url( 'profile.php' )).'" title="'.esc_html__('Admin Profile','newscrunch' ).'">'.$user_identity.'</a>'. '<a href="'. esc_url(wp_logout_url( get_permalink() )).'" title="'.esc_html__('Log out from this Account','newscrunch' ).'">'.' '.esc_html__("Log out",'newscrunch' ).'?</a>' . '</p>',
            'id_submit'             =>  'submit',
            'label_submit'          =>  esc_html__('Post Comment','newscrunch' ),
            'comment_notes_after'   =>  '',
            'comment_notes_before'  =>  '',
            'title_reply_before'   =>  '<div class="spnc-blog-1-wrapper">
                                        <div class="spnc-blog-1-heading">
                                        <h4>',
            'title_reply'           =>  esc_html__('Post Comment', 'newscrunch' ),
            'title_reply_after'        =>  '</h4></div></div>',
            'id_form'               =>  'commentform'
        );
    ob_start();
    comment_form($defaults);
echo '</article>';?>