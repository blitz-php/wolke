<?php if ($paginator->hasPages()): ?>
    <nav>
        <ul class="pagination">
            <?php // Previous Page Link ?>
            <?php if ($paginator->onFirstPage()): ?>
                <li class="disabled" aria-disabled="true" aria-label="<?= lang('Pagination.previous') ?>">
                    <span aria-hidden="true">&lsaquo;</span>
                </li>
            <?php else: ?>
                <li>
                    <a href="<?= $paginator->previousPageUrl(); ?>" rel="prev" aria-label="<?= lang('Pagination.previous'); ?>">&lsaquo;</a>
                </li>
            <?php endif; ?>

            <?php // Pagination Elements ?>
            <?php foreach ($elements as $element): ?>
                <?php // "Three Dots" Separator ?>
                <?php if (is_string($element)): ?>
                    <li class="disabled" aria-disabled="true"><span><?php echo $element; ?></span></li>
                <?php endif; ?>

                <?php // Array Of Links ?>
                <?php if (is_array($element)): ?>
                    <?php foreach ($element as $page => $url): ?>
                        <?php if ($page == $paginator->currentPage()): ?>
                            <li class="active" aria-current="page"><span><?php echo $page; ?></span></li>
                        <?php else: ?>
                            <li><a href="<?php echo $url; ?>"><?php echo $page; ?></a></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php // Next Page Link ?>
            <?php if ($paginator->hasMorePages()): ?>
                <li>
                    <a href="<?php echo $paginator->nextPageUrl(); ?>" rel="next" aria-label="<?= lang('Pagination.next'); ?>">&rsaquo;</a>
                </li>
            <?php else: ?>
                <li class="disabled" aria-disabled="true" aria-label="<?= lang('Pagination.next'); ?>">
                    <span aria-hidden="true">&rsaquo;</span>
                </li>
            <?php endif; ?>
        </ul>
    </nav>
<?php endif; ?>
