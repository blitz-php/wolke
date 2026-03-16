<?php if ($paginator->hasPages()): ?>
    <nav>
        <ul class="pagination">
            <?php // Previous Page Link ?>
            <?php if ($paginator->onFirstPage()): ?>
                <li class="disabled" aria-disabled="true"><span><?= lang('Pagination.previous'); ?></span></li>
            <?php else: ?>
                <li><a href="<?php echo $paginator->previousPageUrl(); ?>" rel="prev"><?= lang('Pagination.previous'); ?></a></li>
            <?php endif; ?>

            <?php // Next Page Link ?>
            <?php if ($paginator->hasMorePages()): ?>
                <li><a href="<?php echo $paginator->nextPageUrl(); ?>" rel="next"><?= lang('Pagination.next'); ?></a></li>
            <?php else: ?>
                <li class="disabled" aria-disabled="true"><span><?= lang('Pagination.next'); ?></span></li>
            <?php endif; ?>
        </ul>
    </nav>
<?php endif; ?>
