<?php if ($paginator->hasPages()): ?>
    <div class="ui pagination menu" role="navigation">
        <?php // Previous Page Link ?>
        <?php if ($paginator->onFirstPage()): ?>
            <a class="icon item disabled" aria-disabled="true" aria-label="<?= lang('Pagination.previous'); ?>"> <i class="left chevron icon"></i> </a>
        <?php else: ?>
            <a class="icon item" href="<?php echo $paginator->previousPageUrl(); ?>" rel="prev" aria-label="<?= lang('Pagination.previous'); ?>"> <i class="left chevron icon"></i> </a>
        <?php endif; ?>

        <?php // Pagination Elements ?>
        <?php foreach ($elements as $element): ?>
            <?php // "Three Dots" Separator ?>
            <?php if (is_string($element)): ?>
                <a class="icon item disabled" aria-disabled="true"><?php echo $element; ?></a>
            <?php endif; ?>

            <?php // Array Of Links ?>
            <?php if (is_array($element)): ?>
                <?php foreach ($element as $page => $url): ?>
                    <?php if ($page == $paginator->currentPage()): ?>
                        <a class="item active" href="<?php echo $url; ?>" aria-current="page"><?php echo $page; ?></a>
                    <?php else: ?>
                        <a class="item" href="<?php echo $url; ?>"><?php echo $page; ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php // Next Page Link ?>
        <?php if ($paginator->hasMorePages()): ?>
            <a class="icon item" href="<?php echo $paginator->nextPageUrl(); ?>" rel="next" aria-label="<?= lang('Pagination.next'); ?>"> <i class="right chevron icon"></i> </a>
        <?php else: ?>
            <a class="icon item disabled" aria-disabled="true" aria-label="<?= lang('Pagination.next'); ?>"> <i class="right chevron icon"></i> </a>
        <?php endif; ?>
    </div>
<?php endif; ?>
