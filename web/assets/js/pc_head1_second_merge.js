(function () {
    'use strict';

    function parsePayload(panel) {
        if (!panel) return {};
        try {
            const payload = JSON.parse(panel.dataset.conditionalSecond || '{}');
            return payload && typeof payload === 'object' ? payload : {};
        } catch (error) {
            return {};
        }
    }

    function isMlV1(panel) {
        return String(panel && panel.dataset.probabilitySource || '') === 'ai_place_v1_joint120';
    }

    function cell(text, className) {
        const td = document.createElement('td');
        td.textContent = text;
        td.className = className || 'pc-conditional-rate-table-value';
        return td;
    }

    function boatByCourse(data) {
        const result = {};
        Object.keys(data).forEach(function (key) {
            const block = data[key] || {};
            const headCourse = Number(block.head_course || key);
            const headBoat = Number(block.head_boat || 0);
            if (headCourse >= 1 && headCourse <= 6 && headBoat >= 1 && headBoat <= 6) {
                result[headCourse] = headBoat;
            }
            (Array.isArray(block.rows) ? block.rows : []).forEach(function (row) {
                const course = Number(row.second_course || 0);
                const boat = Number(row.second_boat || 0);
                if (course >= 1 && course <= 6 && boat >= 1 && boat <= 6) result[course] = boat;
            });
        });
        for (let course = 1; course <= 6; course++) {
            if (!result[course]) result[course] = course;
        }
        return result;
    }

    function render() {
        const target = document.getElementById('conditional-second-rate-panel');
        const source = document.getElementById('head1-exacta-panel');
        if (!target || !source) return false;

        const data = parsePayload(source);
        const heads = Object.keys(data)
            .map(Number)
            .filter(function (course) { return course >= 1 && course <= 6; })
            .sort(function (a, b) { return a - b; });
        if (!heads.length) return false;

        // 専用の2連単表示はデータ供給元としてDOMに残すが、画面では二重表示しない。
        source.style.display = 'none';

        const courseBoats = boatByCourse(data);
        const ml = isMlV1(source);
        let selected = heads.indexOf(1) >= 0 ? 1 : heads[0];
        let selectedSecond = 0;

        function draw() {
            const block = data[String(selected)] || {};
            const rowByCourse = {};
            (Array.isArray(block.rows) ? block.rows : []).forEach(function (row) {
                rowByCourse[Number(row.second_course)] = row;
            });

            target.innerHTML = '';
            const title = document.createElement('div');
            title.className = 'pc-conditional-rate-title';
            title.textContent = '🤖 AI条件付き2着率' + (ml ? ' v1' : '');
            target.appendChild(title);

            const description = document.createElement('div');
            description.className = 'pc-conditional-rate-description';
            description.textContent = '頭を選ぶと、その艇が1着になった場合の2着率を120通りから100%で表示します。';
            target.appendChild(description);

            const controls = document.createElement('div');
            controls.className = 'pc-conditional-rate-controls';
            heads.forEach(function (course) {
                const boat = Number(courseBoats[course] || course);
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'pc-conditional-rate-button pc-conditional-rate-button--head'
                    + (course === selected ? ' is-selected' : '');
                button.textContent = course + 'C ' + boat + '号艇';
                button.setAttribute('aria-pressed', course === selected ? 'true' : 'false');
                button.addEventListener('click', function () {
                    selected = course;
                    selectedSecond = 0;
                    draw();
                });
                controls.appendChild(button);
            });
            target.appendChild(controls);

            const current = document.createElement('div');
            current.className = 'pc-conditional-rate-selection';
            current.textContent = selected + 'C ' + Number(block.head_boat || courseBoats[selected] || selected) + '号艇が1着の場合';
            target.appendChild(current);

            const scroll = document.createElement('div');
            scroll.className = 'pc-conditional-rate-table-scroll';
            const table = document.createElement('table');
            table.className = 'pc-conditional-rate-table';
            const thead = document.createElement('thead');
            const headRow = document.createElement('tr');
            headRow.className = 'pc-conditional-rate-table-head';
            headRow.appendChild(cell('項目 / 進入', 'pc-conditional-rate-table-heading'));
            for (let course = 1; course <= 6; course++) {
                const boat = Number(courseBoats[course] || course);
                headRow.appendChild(cell(course + 'コース / ' + boat + '号艇', 'pc-conditional-rate-table-heading'));
            }
            thead.appendChild(headRow);
            table.appendChild(thead);

            const tbody = document.createElement('tbody');
            const rates = document.createElement('tr');
            rates.appendChild(cell('AI条件付き2着率' + (ml ? ' v1' : ''), 'pc-conditional-rate-table-label'));
            for (let course = 1; course <= 6; course++) {
                const row = rowByCourse[course];
                let label = '-';
                if (course !== selected && row && Number.isFinite(Number(row.ai))) {
                    const rank = Number(row.ai_rank || 0);
                    label = (Number(row.ai) * 100).toFixed(1) + '%' + (rank > 0 ? '（' + rank + '位）' : '');
                }
                rates.appendChild(cell(label, 'pc-conditional-rate-table-value'
                    + (label === '-' ? ' is-unavailable' : '')));
            }
            tbody.appendChild(rates);
            table.appendChild(tbody);
            scroll.appendChild(table);
            target.appendChild(scroll);

            const secondCourses = Object.keys(rowByCourse).map(Number).sort(function (a, b) { return a - b; });
            if (!rowByCourse[selectedSecond]) {
                selectedSecond = secondCourses.slice().sort(function (a, b) {
                    return Number(rowByCourse[b].ai) - Number(rowByCourse[a].ai);
                })[0] || 0;
            }
            if (selectedSecond > 0) {
                const secondTitle = document.createElement('div');
                secondTitle.className = 'pc-conditional-rate-selection pc-conditional-rate-second-title';
                secondTitle.textContent = '2着を選択して3着候補を見る';
                target.appendChild(secondTitle);

                const secondControls = document.createElement('div');
                secondControls.className = 'pc-conditional-rate-controls pc-conditional-rate-controls--second';
                secondCourses.forEach(function (course) {
                    const boat = Number(courseBoats[course] || course);
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'pc-conditional-rate-button pc-conditional-rate-button--second'
                        + (course === selectedSecond ? ' is-selected' : '');
                    button.textContent = selected + '-' + course + '（' + boat + '号艇）';
                    button.setAttribute('aria-pressed', course === selectedSecond ? 'true' : 'false');
                    button.addEventListener('click', function () {
                        selectedSecond = course;
                        draw();
                    });
                    secondControls.appendChild(button);
                });
                target.appendChild(secondControls);

                const thirdRows = (block.third_by_second_course || {})[String(selectedSecond)] || [];
                const thirdByCourse = {};
                thirdRows.forEach(function (row) { thirdByCourse[Number(row.third_course)] = row; });
                const thirdTitle = document.createElement('div');
                thirdTitle.className = 'pc-conditional-rate-third-title';
                thirdTitle.textContent = 'AI条件付き3着率' + (ml ? ' v1' : '') + '：' + selected + '-' + selectedSecond + 'の場合';
                target.appendChild(thirdTitle);

                const thirdScroll = document.createElement('div');
                thirdScroll.className = 'pc-conditional-rate-table-scroll';
                const thirdTable = document.createElement('table');
                thirdTable.className = 'pc-conditional-rate-table pc-conditional-rate-table--third';
                const thirdBody = document.createElement('tbody');
                const thirdRateRow = document.createElement('tr');
                thirdRateRow.appendChild(cell('AI条件付き3着率' + (ml ? ' v1' : ''), 'pc-conditional-rate-table-label'));
                for (let course = 1; course <= 6; course++) {
                    const row = thirdByCourse[course];
                    let label = '-';
                    if (row && Number.isFinite(Number(row.ai))) {
                        label = (Number(row.ai) * 100).toFixed(1) + '%（' + Number(row.ai_rank || 0) + '位）';
                    }
                    thirdRateRow.appendChild(cell(label, 'pc-conditional-rate-table-value'
                        + (label === '-' ? ' is-unavailable' : '')));
                }
                thirdBody.appendChild(thirdRateRow);
                thirdTable.appendChild(thirdBody);
                thirdScroll.appendChild(thirdTable);
                target.appendChild(thirdScroll);
            }

            const note = document.createElement('div');
            note.className = 'pc-conditional-rate-note';
            note.textContent = '2着率は P（2着 | ' + selected + 'Cが1着）、3着率は P（3着 | ' + selected + 'C-選択2着）。' + (ml
                ? ' 展示情報反映後は機械学習120通り v1 を使用。'
                : ' 展示情報取得前は従来の出目確率を使用。');
            target.appendChild(note);
        }

        draw();
        return true;
    }

    function schedule() {
        let tries = 100;
        function run() {
            if (render()) return;
            if (tries-- > 0) window.setTimeout(run, 50);
        }
        run();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', schedule);
    } else {
        schedule();
    }
})();
