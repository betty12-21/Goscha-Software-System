<?php
/**
 * Beauty-php-ai — App shell footer (main close + scripts).
 */
?>
                </div><!-- /.page-content -->
            </div><!-- /.container-fluid -->
        </main>
    </div><!-- /.app-body -->

    <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer">
        <?php render_toasts(); ?>
    </div>
</div><!-- /.bsai-app -->

<div class="modal fade" id="bsaiConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 shadow-lg">
            <div class="modal-body text-center p-4">
                <div class="confirm-icon mb-3"><i class="bi bi-exclamation-triangle text-danger"></i></div>
                <h5 class="modal-title mb-2">Are you sure?</h5>
                <p class="text-muted mb-4" id="bsaiConfirmText">Are you sure you want to delete this record?</p>
                <form method="post" id="bsaiConfirmForm">
                    <?php echo csrf_field(); ?>
                    <div class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger rounded-pill px-4" id="bsaiConfirmBtn">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>window.BASE_URL = '<?php echo base_url(); ?>';</script>
<script src="<?php echo base_url('assets/js/app.js'); ?>"></script>
<script src="<?php echo base_url('assets/js/combobox.js'); ?>"></script>
</body>
</html>
