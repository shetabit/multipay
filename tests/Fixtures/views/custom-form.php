<?php
/**
 * A minimal redirection form view, used to assert that a custom view path is respected.
 *
 * @var string $action
 * @var array  $inputs
 * @var string $method
 */
?>
custom-view:<?php echo $method; ?>:<?php echo $action; ?>:<?php echo implode(',', array_keys($inputs)); ?>
