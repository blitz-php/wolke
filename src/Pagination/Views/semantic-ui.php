<?php if ($paginator->hasPages()): ?>
    <div class="ui pagination menu" role="navigation">
        <?php // Lien de la page précédente?>
        <?php if ($paginator->onFirstPage()): ?>
            <a class="icon item disabled" aria-disabled="true" aria-label="<?= lang('Pagination.previous'); ?>"> <i class="left chevron icon"></i> </a>
        <?php else: ?>
            <a class="icon item" href="<?= $paginator->previousPageUrl(); ?>" rel="prev" aria-label="<?= lang('Pagination.previous'); ?>"> <i class="left chevron icon"></i> </a>
        <?php endif; ?>

        <?php // Éléments de pagination?>
        <?php foreach ($elements as $element): ?>
            <?php // Séparateur "Trois points"?>
            <?php if (is_string($element)): ?>
                <a class="icon item disabled" aria-disabled="true"><?= $element; ?></a>
            <?php endif; ?>

            <?php // Tableau de liens?>
            <?php if (is_array($element)): ?>
                <?php foreach ($element as $page => $url): ?>
                    <?php if ($page === $paginator->currentPage()): ?>
                        <a class="item active" href="<?= $url; ?>" aria-current="page"><?= $page; ?></a>
                    <?php else: ?>
                        <a class="item" href="<?= $url; ?>"><?= $page; ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php // Lien de la page suivante?>
        <?php if ($paginator->hasMorePages()): ?>
            <a class="icon item" href="<?= $paginator->nextPageUrl(); ?>" rel="next" aria-label="<?= lang('Pagination.next'); ?>"> <i class="right chevron icon"></i> </a>
        <?php else: ?>
            <a class="icon item disabled" aria-disabled="true" aria-label="<?= lang('Pagination.next'); ?>"> <i class="right chevron icon"></i> </a>
        <?php endif; ?>
    </div>
<?php endif; ?>
