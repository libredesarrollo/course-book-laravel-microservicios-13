<?php

test('the home page redirects to the task list', function () {
    $this->get('/')->assertRedirect(route('tasks.index'));
});
