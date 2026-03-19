<?php if ($paginator->hasPages()): ?>
    <nav class="d-flex justify-items-center justify-content-between">
        <div class="d-flex justify-content-between flex-fill d-sm-none">
            <ul class="pagination">
                <?php // Lien de la page précédente?>
                <?php if ($paginator->onFirstPage()): ?>
                    <li class="page-item disabled" aria-disabled="true">
                        <span class="page-link"><?= lang('Pagination.previous'); ?></span>
                    </li>
                <?php else: ?>
                    <li class="page-item">
                        <a class="page-link" href="<?= $paginator->previousPageUrl(); ?>" rel="prev"><?= lang('Pagination.previous'); ?></a>
                    </li>
                <?php endif; ?>

                <?php // Lien de la page suivante?>
                <?php if ($paginator->hasMorePages()): ?>
                    <li class="page-item">
                        <a class="page-link" href="<?= $paginator->nextPageUrl(); ?>" rel="next"><?= lang('Pagination.next'); ?></a>
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
                    <?= __('Affichage'); ?>
                    <span class="fw-semibold"><?= $paginator->firstItem(); ?></span>
                    <?= __('à'); ?>
                    <span class="fw-semibold"><?= $paginator->lastItem(); ?></span>
                    <?= __('sur'); ?>
                    <span class="fw-semibold"><?= $paginator->total(); ?></span>
                    <?= __('résultats'); ?>
                </p>
            </div>

            <div>
                <ul class="pagination">
                    <?php // Lien de la page précédente?>
                    <?php if ($paginator->onFirstPage()): ?>
                        <li class="page-item disabled" aria-disabled="true" aria-label="<?= lang('Pagination.previous'); ?>">
                            <span class="page-link" aria-hidden="true">&lsaquo;</span>
                        </li>
                    <?php else: ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= $paginator->previousPageUrl(); ?>" rel="prev" aria-label="<?= lang('Pagination.previous'); ?>">&lsaquo;</a>
                        </li>
                    <?php endif; ?>

                    <?php // Éléments de pagination?>
                    <?php foreach ($elements as $element): ?>
                        <?php // Séparateur "Trois points"?>
                        <?php if (is_string($element)): ?>
                            <li class="page-item disabled" aria-disabled="true"><span class="page-link"><?= $element; ?></span></li>
                        <?php endif; ?>

                        <?php // Tableau de liens?>
                        <?php if (is_array($element)): ?>
                            <?php foreach ($element as $page => $url): ?>
                                <?php if ($page === $paginator->currentPage()): ?>
                                    <li class="page-item active" aria-current="page"><span class="page-link"><?= $page; ?></span></li>
                                <?php else: ?>
                                    <li class="page-item"><a class="page-link" href="<?= $url; ?>"><?= $page; ?></a></li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php // Lien de la page suivante?>
                    <?php if ($paginator->hasMorePages()): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?= $paginator->nextPageUrl(); ?>" rel="next" aria-label="<?= lang('Pagination.next'); ?>">&rsaquo;</a>
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
