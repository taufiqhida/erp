<script setup>
import { computed, ref, watch } from 'vue';

// Input angka dengan separator ribuan (100000 → 100.000) yang tampil langsung
// saat mengetik. Nilai yang di-emit tetap angka mentah (100000), jadi
// database/backend tidak terpengaruh — murni format tampilan.
//
// plain=true: tanpa separator ribuan dan boleh desimal (untuk mode persen
// pada field yang bisa berganti antara % dan Rp).
const props = defineProps({
    modelValue: { type: [Number, String], default: '' },
    plain: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'commit']);

const sanitize = (s, plain) => {
    let out = String(s).replace(plain ? /[^0-9.]/g : /\D/g, '');
    if (plain) {
        const i = out.indexOf('.');
        if (i !== -1) out = out.slice(0, i + 1) + out.slice(i + 1).replace(/\./g, '');
    }
    return out.replace(/^0+(?=\d)/, '');
};

const toRaw = (v) => {
    if (v === null || v === undefined || v === '') return '';
    const n = Number(v);
    if (Number.isNaN(n)) return '';
    return props.plain ? String(n) : String(Math.round(n));
};

const toValue = (raw) => {
    if (raw === '' || raw === '.') return '';
    const n = Number(raw);
    return Number.isNaN(n) ? '' : n;
};

const raw = ref(toRaw(props.modelValue));

watch(() => props.modelValue, (v) => {
    const empty = v === '' || v === null || v === undefined;
    const same = empty ? raw.value === '' : (raw.value !== '' && Number(raw.value) === Number(v));
    if (!same) raw.value = toRaw(v);
});

watch(() => props.plain, () => {
    const v = toValue(raw.value);
    raw.value = toRaw(v);
});

const display = computed(() =>
    props.plain ? raw.value : raw.value.replace(/\B(?=(\d{3})+(?!\d))/g, '.')
);

const onInput = (e) => {
    const el = e.target;
    const isSignificant = (ch) => (props.plain ? /[0-9.]/ : /\d/).test(ch);
    const pos = el.selectionStart ?? el.value.length;
    const sigBefore = [...el.value.slice(0, pos)].filter(isSignificant).length;

    raw.value = sanitize(el.value, props.plain);
    el.value = display.value;

    let newPos = 0;
    if (sigBefore > 0) {
        newPos = el.value.length;
        let count = 0;
        for (let i = 0; i < el.value.length; i++) {
            if (isSignificant(el.value[i])) count++;
            if (count === sigBefore) { newPos = i + 1; break; }
        }
    }
    el.setSelectionRange(newPos, newPos);

    emit('update:modelValue', toValue(raw.value));
};

const onChange = () => emit('commit', toValue(raw.value));

const onBlur = () => {
    if (props.plain && raw.value.endsWith('.')) raw.value = raw.value.slice(0, -1);
};
</script>

<template>
    <input
        type="text"
        :inputmode="plain ? 'decimal' : 'numeric'"
        autocomplete="off"
        :value="display"
        @input="onInput"
        @change="onChange"
        @blur="onBlur"
    />
</template>
