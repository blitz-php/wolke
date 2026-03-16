<?php if ($paginator->hasPages()): ?>
    <nav class="d-flex justify-items-center justify-content-between">
        <div class="d-flex justify-content-between flex-fill d-sm-none">
            <ul class="pagination">
                <?php // Previous Page Link ?>
                <?php if ($paginator->onFirstPage()): ?>
                    <li class="page-item disabled" aria-disabled="true">
                        <span class="page-link"><?= lang('Pagination.previous'); ?></span>
                    </li>
                <?php else: ?>
                    <li class="page-item">
                        <a class="page-link" href="<?php echo $paginator->previousPageUrl(); ?>" rel="prev"><?= lang('Pagination.previous'); ?></a>
                    </li>
                <?php endif; ?>

                <?php // Next Page Link ?>
                <?php if ($paginator->hasMorePages()): ?>
                    <li class="page-item">
                        <a class="page-link" href="<?php echo $paginator->nextPageUrl(); ?>" rel="next"><?= lang('Pagination.next'); ?></a>
                    </li>
                <?php else: ?>
                    <li class="page-item disabled" aria-disabled="true">
                        <span class="page-link"><?= lang('Pagination.next'); ?></span>
                    </li>
                <?php endif; ?>
            </ul>
        </div>

        <div class="d-none flex-sm-fill d-sm-flex align-items-sm-center justify-content-sm-between">
            <div>
                <p class="small text-muted">
                    <?php echo __('Showing'); ?>
                    <span class="fw-semibold"><?php echo $paginator->firstItem(); ?></span>
                    <?php echo __('to'); ?>
                    <span class="fw-semibold"><?php echo $paginator->lastItem(); ?></span>
                    <?php echo __('of'); ?>
                    <span class="fw-semibold"><?php echo $paginator->total(); ?></span>
                    <?php echo __('results'); ?>
                </p>
            </div>

            <div>
                <ul class="pagination">
                    <?php // Previous Page Link ?>
                    <?php if ($paginator->onFirstPage()): ?>
                        <li class="page-item disabled" aria-disabled="true" aria-label="<?= lang('Pagination.previous'); ?>">
                            <span class="page-link" aria-hidden="true">&lsaquo;</span>
                        </li>
                    <?php else: ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo $paginator->previousPageUrl(); ?>" rel="prev" aria-label="<?= lang('Pagination.previous'); ?>">&lsaquo;</a>
                        </li>
                    <?php endif; ?>

                    <?php // Pagination Elements ?>
                    <?php foreach ($elements as $element): ?>
                        <?php // "Three Dots" Separator ?>
                        <?php if (is_string($element)): ?>
                            <li class="page-item disabled" aria-disabled="true"><span class="page-link"><?php echo $element; ?></span></li>
                        <?php endif; ?>

                        <?php // Array Of Links ?>
                        <?php if (is_array($element)): ?>
                            <?php foreach ($element as $page => $url): ?>
                                <?php if ($page == $paginator->currentPage()): ?>
                                    <li class="page-item active" aria-current="page"><span class="page-link"><?php echo $page; ?></span></li>
                                <?php else: ?>
                                    <li class="page-item"><a class="page-link" href="<?php echo $url; ?>"><?php echo $page; ?></a></li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php // Next Page Link ?>
                    <?php if ($paginator->hasMorePages()): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo $paginator->nextPageUrl(); ?>" rel="next" aria-label="<?= lang('Pagination.next'); ?>">&rsaquo;</a>
                        </li>
                    <?php else: ?>
                        <li class="page-item disabled" aria-disabled="true" aria-label="<?= lang('Pagination.next'); ?>">
                            <span class="page-link" aria-hidden="true">&rsaquo;</span>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
<?php endif; ?>
