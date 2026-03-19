<?php if ($paginator->hasPages()): ?>
    <nav>
        <ul class="pagination">
            <?php // Lien de la page précédente?>
            <?php if ($paginator->onFirstPage()): ?>
                <li class="disabled" aria-disabled="true" aria-label="<?= lang('Pagination.previous') ?>">
                    <span aria-hidden="true">&lsaquo;</span>
                </li>
            <?php else: ?>
                <li>
                    <a href="<?= $paginator->previousPageUrl(); ?>" rel="prev" aria-label="<?= lang('Pagination.previous'); ?>">&lsaquo;</a>
                </li>
            <?php endif; ?>

            <?php // Éléments de pagination?>
            <?php foreach ($elements as $element): ?>
                <?php // Séparateur "Trois points"?>
                <?php if (is_string($element)): ?>
                    <li class="disabled" aria-disabled="true"><span><?= $element; ?></span></li>
                <?php endif; ?>

                <?php // Tableau de liens?>
                <?php if (is_array($element)): ?>
                    <?php foreach ($element as $page => $url): ?>
                        <?php if ($page === $paginator->currentPage()): ?>
                            <li class="active" aria-current="page"><span><?= $page; ?></span></li>
                        <?php else: ?>
                            <li><a href="<?= $url; ?>"><?= $page; ?></a></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php // Lien de la page suivante?>
            <?php if ($paginator->hasMorePages()): ?>
                <li>
                    <a href="<?= $paginator->nextPageUrl(); ?>" rel="next" aria-label="<?= lang('Pagination.next'); ?>">&rsaquo;</a>
                </li>
            <?php else: ?>
                <li class="disabled" aria-disabled="true" aria-label="<?= lang('Pagination.next'); ?>">
                    <span aria-hidden="true">&rsaquo;</span>
                </li>
            <?php endif; ?>
        </ul>
    </nav>
<?php endif; ?>
