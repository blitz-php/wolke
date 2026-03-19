<?php if ($paginator->hasPages()): ?>
    <nav>
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
    </nav>
<?php endif; ?>
